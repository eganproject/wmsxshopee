<?php

namespace App\Services\Tiktok;

use App\Models\SalesOrder;
use App\Support\ConnectionError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
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

        return $this->parseOptions($this->api($order)->get("/fulfillment/packages/{$order->tiktok_package_id}/handover_time_slots"));
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function parseOptions(array $response): array
    {
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
        [$body, $record] = $this->prepare($method, $input, $options);

        $api = $this->api($order);
        $api->post("/fulfillment/packages/{$order->tiktok_package_id}/ship", $body);

        $this->markArranged($order, $record, $userId);
        $this->fetchTracking($order, $api);
    }

    /**
     * Isian ship & catatan pesanan untuk cara kirim yang dipilih.
     *
     * @param  array{pickup_slot?: string|null}  $input
     * @param  array<string, mixed>  $options
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function prepare(string $method, array $input, array $options): array
    {
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

        return [$body, $record];
    }

    /** @param  array<string, mixed>  $record */
    private function markArranged(SalesOrder $order, array $record, ?int $userId): void
    {
        $order->update([
            ...$record,
            'tiktok_order_status' => 'AWAITING_COLLECTION',
            'tiktok_sync_error' => null,
            'shipment_arranged_at' => now(),
            'shipment_arranged_by' => $userId,
        ]);
    }

    /**
     * Atur banyak pesanan sekaligus: pickup memakai slot paling awal, drop off tanpa isian.
     *
     * @param  Collection<int, SalesOrder>  $orders
     * @return array{arranged: list<string>, failed: array<string, string>}
     */
    public function arrangeMany(Collection $orders, string $method, ?int $userId): array
    {
        $failed = [];
        $ready = collect();

        foreach ($orders as $order) {
            try {
                $this->ensureArrangeable($order);
                $ready->push($order);
            } catch (ValidationException $e) {
                $failed[$order->number] = collect($e->errors())->flatten()->first();
            }
        }

        // Per toko: ambil slot semua paket sekaligus, lalu atur semuanya sekaligus (paralel).
        foreach ($ready->groupBy('tiktok_shop_id') as $shopOrders) {
            try {
                $api = $this->api($shopOrders->first());
                $slots = $api->many($shopOrders->mapWithKeys(fn (SalesOrder $order) => [$order->id => [
                    'method' => 'GET',
                    'path' => "/fulfillment/packages/{$order->tiktok_package_id}/handover_time_slots",
                ]])->all());

                $planned = [];
                foreach ($shopOrders as $order) {
                    try {
                        if ($slots[$order->id] instanceof TiktokException) {
                            throw $slots[$order->id];
                        }
                        $options = $this->parseOptions($slots[$order->id]);
                        $input = $method === 'pickup' ? ['pickup_slot' => $options['pickup']['slots'][0]['key'] ?? null] : [];
                        $planned[$order->id] = $this->prepare($method, $input, $options);
                    } catch (ValidationException $e) {
                        $failed[$order->number] = collect($e->errors())->flatten()->first();
                    } catch (TiktokException $e) {
                        $failed[$order->number] = $e->getMessage();
                    }
                }

                $toShip = $shopOrders->filter(fn (SalesOrder $order) => isset($planned[$order->id]))->values();
                $shipped = $api->many($toShip->mapWithKeys(fn (SalesOrder $order) => [$order->id => [
                    'method' => 'POST',
                    'path' => "/fulfillment/packages/{$order->tiktok_package_id}/ship",
                    'body' => $planned[$order->id][0],
                ]])->all());

                $done = collect();
                foreach ($toShip as $order) {
                    if ($shipped[$order->id] instanceof TiktokException) {
                        $failed[$order->number] = $shipped[$order->id]->getMessage();

                        continue;
                    }

                    $this->markArranged($order, $planned[$order->id][1], $userId);
                    $done->push($order);
                }

                $this->fetchTrackingMany($api, $done);
            } catch (TiktokException $e) {
                foreach ($shopOrders as $order) {
                    if (! $order->shipment_arranged_at && ! isset($failed[$order->number])) {
                        $failed[$order->number] = $e->getMessage();
                    }
                }
            }
        }

        $arranged = $orders->filter(fn (SalesOrder $order) => ! isset($failed[$order->number]) && $ready->contains($order) && $order->shipment_arranged_at)
            ->pluck('number')->values()->all();

        return ['arranged' => $arranged, 'failed' => $failed];
    }

    /** Unduh label resi (PDF) satu pesanan TikTok. */
    public function label(SalesOrder $order): string
    {
        $this->ensureTiktok($order);
        $result = $this->labels(collect([$order]));

        if ($result['failed'] !== []) {
            throw new TiktokException(reset($result['failed']));
        }

        return reset($result['pdfs']);
    }

    /**
     * Unduh label resi beberapa pesanan TikTok dari toko yang sama sekaligus (paralel).
     *
     * @param  Collection<int, SalesOrder>  $orders
     * @return array{pdfs: array<int, string>, failed: array<string, string>} PDF per id pesanan, alasan gagal per nomor pesanan
     */
    public function labels(Collection $orders): array
    {
        $pdfs = [];
        $failed = [];
        $valid = collect();

        foreach ($orders as $order) {
            try {
                $this->ensureTiktok($order);
                $valid->push($order);
            } catch (ValidationException $e) {
                $failed[$order->number] = collect($e->errors())->flatten()->first();
            }
        }

        if ($valid->pluck('tiktok_shop_id')->unique()->count() > 1) {
            throw ValidationException::withMessages(['orders' => 'Label TikTok hanya bisa diambil bersamaan untuk pesanan dari toko yang sama.']);
        }
        if ($valid->isEmpty()) {
            return ['pdfs' => $pdfs, 'failed' => $failed];
        }

        $api = $this->api($valid->first());
        $this->fetchTrackingMany($api, $valid->filter(fn (SalesOrder $order) => blank($order->tracking_number))->values());

        $documents = $api->many($valid->mapWithKeys(fn (SalesOrder $order) => [$order->id => [
            'method' => 'GET',
            'path' => "/fulfillment/packages/{$order->tiktok_package_id}/shipping_documents",
            'query' => ['document_type' => 'SHIPPING_LABEL', 'document_size' => config('tiktok.label_size', 'A6')],
        ]])->all());

        $urls = [];
        foreach ($valid as $order) {
            $document = $documents[$order->id] ?? null;

            if ($document instanceof TiktokException) {
                $failed[$order->number] = $document->getMessage();
            } elseif (blank($document['doc_url'] ?? null)) {
                $failed[$order->number] = 'TikTok belum menyediakan label resi untuk paket ini. Coba lagi sebentar lagi.';
            } else {
                $urls[$order->id] = $document['doc_url'];
            }
        }

        foreach (array_chunk($urls, max(1, (int) config('tiktok.concurrency', 4)), true) as $chunk) {
            $ids = array_keys($chunk);
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($i) => $pool->as((string) $i)->connectTimeout(config('tiktok.connect_timeout', 10))->timeout(config('tiktok.timeout'))->get($chunk[$ids[$i]]),
                array_keys($ids),
            ));

            foreach ($ids as $i => $id) {
                $order = $valid->firstWhere('id', $id);
                $response = $responses[(string) $i] ?? null;

                if (! $response instanceof Response) {
                    $failed[$order->number] = 'Label TikTok gagal diunduh. '.($response instanceof ConnectionException ? ConnectionError::message('TikTok', $response) : 'Coba lagi.');
                } elseif ($response->failed() || ! str_starts_with($response->body(), '%PDF')) {
                    $failed[$order->number] = "Label TikTok tidak bisa diunduh (HTTP {$response->status()}).";
                } else {
                    $pdfs[$id] = $response->body();
                }
            }
        }

        if ($pdfs !== []) {
            SalesOrder::withoutGlobalScope('warehouse_access')->whereKey(array_keys($pdfs))->update(['shipping_label_printed_at' => now()]);
        }

        // Urutan PDF mengikuti urutan pesanan.
        $ordered = [];
        foreach ($orders as $order) {
            if (isset($pdfs[$order->id])) {
                $ordered[$order->id] = $pdfs[$order->id];
            }
        }

        return ['pdfs' => $ordered, 'failed' => $failed];
    }

    /**
     * Ambil resi banyak paket sekaligus; yang belum terbit dibiarkan kosong.
     *
     * @param  Collection<int, SalesOrder>  $orders
     */
    private function fetchTrackingMany(TiktokShopApi $api, Collection $orders): void
    {
        if ($orders->isEmpty()) {
            return;
        }

        try {
            $results = $api->many($orders->mapWithKeys(fn (SalesOrder $order) => [$order->id => [
                'method' => 'GET',
                'path' => "/fulfillment/packages/{$order->tiktok_package_id}",
            ]])->all());
        } catch (TiktokException) {
            return; // Resi diambil lagi saat sinkron berikutnya.
        }

        foreach ($orders as $order) {
            $tracking = is_array($results[$order->id] ?? null) ? ($results[$order->id]['tracking_number'] ?? null) : null;

            if (filled($tracking)) {
                $order->update(['tracking_number' => mb_substr((string) $tracking, 0, 100)]);
            }
        }
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
