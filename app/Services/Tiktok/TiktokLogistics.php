<?php

namespace App\Services\Tiktok;

use App\Models\SalesOrder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Atur pengiriman pesanan TikTok (dikirim lewat TikTok Shipping) & cetak label
 * resi lewat Fulfillment API 202309. Satu pesanan = satu paket (package_id).
 *
 *   GET  /fulfillment/202309/packages/{id}/handover_time_slots -> pickup (slot jam) / drop off tersedia
 *   POST /fulfillment/202309/packages/{id}/ship                -> handover_method PICKUP / DROP_OFF;
 *                                                                status TikTok jadi AWAITING_COLLECTION
 *   GET  /fulfillment/202309/packages/{id}                     -> resi (tracking_number)
 *   GET  /fulfillment/202309/packages/{id}/shipping_documents  -> doc_url label PDF (SHIPPING_LABEL)
 */
class TiktokLogistics
{
    /**
     * Pilihan cara kirim untuk satu pesanan.
     *
     * @return array{pickup: array{slots: list<array{key: string, start: int, end: int, label: string}>}|null, dropoff: array{url: string|null}|null}
     */
    public function options(SalesOrder $order): array
    {
        $this->ensureTiktok($order);

        $response = $this->api($order)->get("/fulfillment/packages/{$order->tiktok_package_id}/handover_time_slots");

        $slots = collect($response['pickup_slots'] ?? [])
            ->filter(fn ($slot) => ($slot['avaliable'] ?? $slot['available'] ?? true) !== false && isset($slot['start_time'], $slot['end_time']))
            ->map(fn ($slot) => [
                'key' => $slot['start_time'].'-'.$slot['end_time'],
                'start' => (int) $slot['start_time'],
                'end' => (int) $slot['end_time'],
                'label' => $this->slotLabel((int) $slot['start_time'], (int) $slot['end_time']),
            ])->values()->all();

        return [
            'pickup' => ($response['can_pickup'] ?? $slots !== []) ? ['slots' => $slots] : null,
            'dropoff' => ($response['can_drop_off'] ?? false) ? ['url' => $response['drop_off_point_url'] ?? null] : null,
        ];
    }

    /**
     * Atur pengiriman di TikTok lalu catat di pesanan.
     *
     * @param  array{pickup_slot?: string|null}  $input
     * @param  array<string, mixed>|null  $options  hasil options() bila sudah diambil
     */
    public function arrange(SalesOrder $order, string $method, array $input, ?int $userId, ?array $options = null): void
    {
        $this->ensureArrangeable($order);
        $options ??= $this->options($order);
        $available = $options[$method] ?? null;

        if (! $available) {
            throw ValidationException::withMessages(['method' => 'Cara kirim '.(SalesOrder::SHIPPING_METHODS[$method]['label'] ?? $method).' tidak tersedia untuk paket ini di TikTok.']);
        }

        $body = ['handover_method' => $method === 'pickup' ? 'PICKUP' : 'DROP_OFF'];
        $record = ['shipping_method' => $method, 'pickup_at' => null, 'pickup_slot' => null, 'pickup_address' => null, 'dropoff_point' => null];

        if ($method === 'pickup') {
            $slot = collect($available['slots'])->firstWhere('key', (string) ($input['pickup_slot'] ?? ''));

            if (! $slot && $available['slots'] !== []) {
                throw ValidationException::withMessages(['pickup_slot' => 'Pilih jadwal pickup.']);
            }

            if ($slot) {
                $body['pickup_slot'] = ['start_time' => $slot['start'], 'end_time' => $slot['end']];
                $record['pickup_slot'] = $slot['label'];
                $record['pickup_at'] = Carbon::createFromTimestamp($slot['start'], config('app.timezone'));
            }
            $record['pickup_address'] = 'Alamat pickup toko di TikTok Shop Seller Center';
        } else {
            $record['dropoff_point'] = 'Titik drop off kurir TikTok';
        }

        $api = $this->api($order);
        $api->post("/fulfillment/packages/{$order->tiktok_package_id}/ship", $body);

        $order->update([
            ...$record,
            'tiktok_order_status' => 'AWAITING_COLLECTION',
            'tiktok_sync_error' => null,
            'shipment_arranged_at' => now(),
            'shipment_arranged_by' => $userId,
        ]);

        $this->fetchTracking($order, $api);
    }

    /**
     * Atur banyak pesanan sekaligus: pickup memakai slot paling awal, drop off tanpa isian.
     *
     * @param  Collection<int, SalesOrder>  $orders
     * @return array{arranged: list<string>, failed: array<string, string>}
     */
    public function arrangeMany(Collection $orders, string $method, ?int $userId): array
    {
        $result = ['arranged' => [], 'failed' => []];

        foreach ($orders as $order) {
            try {
                $this->ensureArrangeable($order);
                $options = $this->options($order);
                $input = $method === 'pickup' ? ['pickup_slot' => $options['pickup']['slots'][0]['key'] ?? null] : [];

                $this->arrange($order, $method, $input, $userId, $options);
                $result['arranged'][] = $order->number;
            } catch (ValidationException $e) {
                $result['failed'][$order->number] = collect($e->errors())->flatten()->first();
            } catch (TiktokException $e) {
                $result['failed'][$order->number] = $e->getMessage();
            }
        }

        return $result;
    }

    /** Unduh label resi (PDF) satu pesanan TikTok. */
    public function label(SalesOrder $order): string
    {
        $this->ensureTiktok($order);
        $api = $this->api($order);

        if (blank($order->tracking_number)) {
            $this->fetchTracking($order, $api);
        }

        $document = $api->get("/fulfillment/packages/{$order->tiktok_package_id}/shipping_documents", [
            'document_type' => 'SHIPPING_LABEL',
            'document_size' => config('tiktok.label_size', 'A6'),
        ]);

        if (blank($document['doc_url'] ?? null)) {
            throw new TiktokException('TikTok belum menyediakan label resi untuk paket ini. Coba lagi sebentar lagi.');
        }

        try {
            $response = Http::timeout(config('tiktok.timeout'))->get($document['doc_url']);
        } catch (ConnectionException $e) {
            throw new TiktokException('Tidak bisa mengunduh label TikTok: '.$e->getMessage());
        }

        if ($response->failed() || ! str_starts_with($response->body(), '%PDF')) {
            throw new TiktokException("Label TikTok tidak bisa diunduh (HTTP {$response->status()}).");
        }

        $order->update(['shipping_label_printed_at' => now()]);

        return $response->body();
    }

    public function fetchTracking(SalesOrder $order, TiktokShopApi $api): bool
    {
        try {
            $tracking = $api->get("/fulfillment/packages/{$order->tiktok_package_id}")['tracking_number'] ?? null;
        } catch (TiktokException) {
            return false; // Resi belum terbit; diambil lagi saat sinkron berikutnya.
        }

        if (filled($tracking)) {
            $order->update(['tracking_number' => mb_substr((string) $tracking, 0, 100)]);

            return true;
        }

        return false;
    }

    private function ensureTiktok(SalesOrder $order): void
    {
        if (! $order->isTiktok() || ! $order->tiktokShop) {
            throw ValidationException::withMessages(['order' => "{$order->number} bukan pesanan TikTok."]);
        }

        if (blank($order->tiktok_package_id)) {
            throw ValidationException::withMessages(['order' => "Paket {$order->number} belum dibuat TikTok. Sinkron ulang toko lalu coba lagi."]);
        }
    }

    private function ensureArrangeable(SalesOrder $order): void
    {
        $this->ensureTiktok($order);

        if (! $order->canArrangeShipment()) {
            throw ValidationException::withMessages(['order' => $order->tiktokShipmentArranged()
                ? "Pengiriman {$order->number} sudah diatur di TikTok."
                : "{$order->number} belum bisa diatur pengirimannya (status {$order->statusLabel()} / TikTok {$order->tiktok_order_status})."]);
        }
    }

    private function api(SalesOrder $order): TiktokShopApi
    {
        return TiktokShopApi::for($order->tiktokShop);
    }

    private function slotLabel(int $start, int $end): string
    {
        $from = Carbon::createFromTimestamp($start, config('app.timezone'));
        $to = Carbon::createFromTimestamp($end, config('app.timezone'));

        return $from->translatedFormat('l, d M Y').' · '.$from->format('H:i').'–'.$to->format('H:i');
    }
}
