<?php

namespace App\Services\Shopee;

use App\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Atur pengiriman pesanan Shopee & cetak label resi (AWB) lewat API logistik.
 *
 *   /logistics/get_shipping_parameter  -> cara kirim yang tersedia: pickup (alamat & slot jam),
 *                                         drop off (cabang bila perlu), non-integrated (resi sendiri)
 *   /logistics/ship_order              -> atur pengiriman; status Shopee jadi PROCESSED
 *   /logistics/get_tracking_number     -> resi (bisa terbit beberapa saat setelah diatur)
 *
 * Label resi:
 *   get_shipping_document_parameter -> create_shipping_document -> get_shipping_document_result
 *   (tunggu READY) -> download_shipping_document (PDF, maks 50 pesanan sekali unduh).
 */
class ShopeeLogistics
{
    /** Urutan pilihan jenis label bila tersedia untuk semua pesanan. */
    public const DOCUMENT_TYPES = ['THERMAL_AIR_WAYBILL', 'NORMAL_AIR_WAYBILL', 'THERMAL_JOB_AIR_WAYBILL', 'NORMAL_JOB_AIR_WAYBILL'];

    public const MAX_DOCUMENTS = 50;

    /**
     * Pilihan cara kirim untuk satu pesanan, sudah dirapikan untuk form.
     *
     * @return array{
     *     pickup: array{needs: list<string>, addresses: list<array<string, mixed>>}|null,
     *     dropoff: array{needs: list<string>, branches: list<array<string, mixed>>, slugs: list<array<string, mixed>>}|null,
     *     non_integrated: array{needs: list<string>}|null,
     * }
     */
    public function options(SalesOrder $order): array
    {
        $this->ensureShopee($order);

        $response = $this->api($order)->get('/api/v2/logistics/get_shipping_parameter', array_filter([
            'order_sn' => $order->external_number,
            'package_number' => $order->shopee_package_number,
        ]))['response'] ?? [];

        $needed = $response['info_needed'] ?? [];

        $options = ['pickup' => null, 'dropoff' => null, 'non_integrated' => null];

        if (array_key_exists('pickup', $needed)) {
            $options['pickup'] = [
                'needs' => $this->needs($needed['pickup']),
                'addresses' => collect($response['pickup']['address_list'] ?? [])
                    ->map(fn (array $address) => [
                        'address_id' => (int) $address['address_id'],
                        'label' => $this->formatAddress($address),
                        'flags' => $address['address_flag'] ?? [],
                        'slots' => collect($address['time_slot_list'] ?? [])
                            ->map(fn (array $slot) => [
                                'pickup_time_id' => (string) $slot['pickup_time_id'],
                                'date' => isset($slot['date']) ? (int) $slot['date'] : null,
                                'label' => $this->slotLabel($slot),
                                'recommended' => in_array('recommended', $slot['flags'] ?? [], true),
                            ])->values()->all(),
                    ])
                    // Alamat pickup lebih dulu, lalu alamat default.
                    ->sortByDesc(fn (array $address) => (in_array('pickup_address', $address['flags'], true) ? 2 : 0) + (in_array('default_address', $address['flags'], true) ? 1 : 0))
                    ->values()->all(),
            ];
        }

        if (array_key_exists('dropoff', $needed)) {
            $options['dropoff'] = [
                'needs' => $this->needs($needed['dropoff']),
                'branches' => collect($response['dropoff']['branch_list'] ?? [])
                    ->map(fn (array $branch) => ['branch_id' => (int) $branch['branch_id'], 'label' => $this->formatAddress($branch)])
                    ->values()->all(),
                'slugs' => collect($response['dropoff']['slug_list'] ?? [])
                    ->map(fn (array $slug) => ['slug' => (string) $slug['slug'], 'label' => (string) ($slug['slug_name'] ?? $slug['slug'])])
                    ->values()->all(),
            ];
        }

        if (array_key_exists('non_integrated', $needed)) {
            $options['non_integrated'] = ['needs' => $this->needs($needed['non_integrated'])];
        }

        return $options;
    }

    /**
     * Atur pengiriman di Shopee lalu catat di pesanan.
     *
     * @param  array{address_id?: int|string|null, pickup_time_id?: string|null, branch_id?: int|string|null, sender_real_name?: string|null, slug?: string|null, tracking_number?: string|null}  $input
     * @param  array<string, mixed>|null  $options  hasil options() bila sudah diambil
     */
    public function arrange(SalesOrder $order, string $method, array $input, ?int $userId, ?array $options = null): void
    {
        $this->ensureArrangeable($order);
        $options ??= $this->options($order);
        $available = $options[$method] ?? null;

        if (! $available) {
            throw ValidationException::withMessages(['method' => 'Cara kirim '.$this->methodName($method).' tidak tersedia untuk kurir pesanan ini.']);
        }

        $payload = [];
        $record = ['shipping_method' => $method === 'non_integrated' ? 'dropoff' : $method, 'pickup_at' => null, 'pickup_slot' => null, 'pickup_address' => null, 'dropoff_point' => null];

        if ($method === 'pickup') {
            $address = collect($available['addresses'])->firstWhere('address_id', (int) ($input['address_id'] ?? 0));
            $slot = $address ? collect($address['slots'])->firstWhere('pickup_time_id', (string) ($input['pickup_time_id'] ?? '')) : null;

            if (! $address) {
                throw ValidationException::withMessages(['address_id' => 'Pilih alamat pickup.']);
            }
            if (! $slot && in_array('pickup_time_id', $available['needs'], true)) {
                throw ValidationException::withMessages(['pickup_time_id' => 'Pilih jadwal pickup.']);
            }

            $payload = array_filter([
                'address_id' => $address['address_id'],
                'pickup_time_id' => $slot['pickup_time_id'] ?? null,
                'tracking_number' => $this->needsTracking($available) ? $this->requiredTracking($input) : null,
            ], fn ($value) => $value !== null);
            $record = [...$record,
                'pickup_address' => $address['label'],
                'pickup_slot' => $slot['label'] ?? null,
                'pickup_at' => isset($slot['date']) ? Carbon::createFromTimestamp($slot['date'], config('app.timezone')) : null,
            ];
        } elseif ($method === 'dropoff') {
            $branch = null;
            if (in_array('branch_id', $available['needs'], true)) {
                $branch = collect($available['branches'])->firstWhere('branch_id', (int) ($input['branch_id'] ?? 0));
                if (! $branch) {
                    throw ValidationException::withMessages(['branch_id' => 'Pilih cabang drop off.']);
                }
            }
            if (in_array('sender_real_name', $available['needs'], true) && blank($input['sender_real_name'] ?? null)) {
                throw ValidationException::withMessages(['sender_real_name' => 'Isi nama pengirim yang mengantar paket.']);
            }
            if (in_array('slug', $available['needs'], true) && blank($input['slug'] ?? null)) {
                throw ValidationException::withMessages(['slug' => 'Pilih kurir drop off.']);
            }

            $payload = array_filter([
                'branch_id' => $branch['branch_id'] ?? null,
                'sender_real_name' => filled($input['sender_real_name'] ?? null) ? trim($input['sender_real_name']) : null,
                'slug' => $input['slug'] ?? null,
                'tracking_number' => $this->needsTracking($available) ? $this->requiredTracking($input) : null,
            ], fn ($value) => $value !== null);
            $record['dropoff_point'] = $branch['label'] ?? 'Cabang / agen kurir terdekat';
        } elseif ($method === 'non_integrated') {
            $payload = ['tracking_number' => $this->requiredTracking($input)];
            $record['dropoff_point'] = 'Kurir non-integrasi (resi diisi sendiri)';
        } else {
            throw ValidationException::withMessages(['method' => 'Cara kirim tidak dikenal.']);
        }

        $api = $this->api($order);
        $api->post('/api/v2/logistics/ship_order', array_filter([
            'order_sn' => $order->external_number,
            'package_number' => $order->shopee_package_number,
            $method => $payload === [] ? new \stdClass : $payload,
        ], fn ($value) => $value !== null));

        $order->update([
            ...$record,
            'shopee_order_status' => 'PROCESSED',
            'shopee_sync_error' => null,
            'shipment_arranged_at' => now(),
            'shipment_arranged_by' => $userId,
            'tracking_number' => $payload['tracking_number'] ?? $order->tracking_number,
        ]);

        $this->fetchTracking($order, $api);
    }

    /**
     * Atur banyak pesanan sekaligus dengan pilihan otomatis: alamat pickup Shopee
     * (atau default) dan slot jam paling awal / yang disarankan; drop off tanpa
     * memilih cabang. Pesanan yang butuh isian lain dilewati dengan alasannya.
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
                $input = [];

                if ($method === 'pickup' && ($pickup = $options['pickup'])) {
                    $address = $pickup['addresses'][0] ?? null;
                    $slots = collect($address['slots'] ?? []);
                    $slot = $slots->firstWhere('recommended', true) ?? $slots->first();
                    $input = ['address_id' => $address['address_id'] ?? null, 'pickup_time_id' => $slot['pickup_time_id'] ?? null];
                } elseif ($method === 'dropoff' && ($dropoff = $options['dropoff'])) {
                    $extra = array_diff($dropoff['needs'], ['sender_real_name']);
                    if ($extra !== []) {
                        throw ValidationException::withMessages(['method' => 'Drop off kurir ini perlu memilih cabang; atur dari halaman pesanan.']);
                    }
                    $input = ['sender_real_name' => $order->warehouse?->pic_name ?: $order->warehouse?->name];
                }

                $this->arrange($order, $method, $input, $userId, $options);
                $result['arranged'][] = $order->number;
            } catch (ValidationException $e) {
                $result['failed'][$order->number] = collect($e->errors())->flatten()->first();
            } catch (ShopeeException $e) {
                $result['failed'][$order->number] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Unduh label resi Shopee (PDF) untuk satu atau beberapa pesanan dari toko yang sama.
     *
     * @param  Collection<int, SalesOrder>  $orders
     */
    public function label(Collection $orders): string
    {
        $orders = $orders->values();

        if ($orders->isEmpty()) {
            throw ValidationException::withMessages(['orders' => 'Tidak ada pesanan Shopee yang labelnya bisa dicetak.']);
        }
        if ($orders->count() > self::MAX_DOCUMENTS) {
            throw ValidationException::withMessages(['orders' => 'Maksimal '.self::MAX_DOCUMENTS.' label sekali cetak.']);
        }
        if ($orders->pluck('shopee_shop_id')->unique()->count() > 1) {
            throw ValidationException::withMessages(['orders' => 'Label hanya bisa dicetak bersamaan untuk pesanan dari toko Shopee yang sama.']);
        }

        $api = $this->api($orders->first());
        foreach ($orders as $order) {
            if (blank($order->tracking_number)) {
                $this->fetchTracking($order, $api);
            }
        }

        $list = $orders->map(fn (SalesOrder $order) => array_filter([
            'order_sn' => $order->external_number,
            'package_number' => $order->shopee_package_number,
        ]))->all();

        $params = collect($api->post('/api/v2/logistics/get_shipping_document_parameter', ['order_list' => $list])['response']['result_list'] ?? []);
        $this->throwFailures($params);
        $type = $this->documentType($params);

        $created = collect($api->post('/api/v2/logistics/create_shipping_document', [
            'order_list' => $orders->map(fn (SalesOrder $order) => array_filter([
                'order_sn' => $order->external_number,
                'package_number' => $order->shopee_package_number,
                'tracking_number' => $order->tracking_number,
                'shipping_document_type' => $type,
            ]))->all(),
        ])['response']['result_list'] ?? []);
        $this->throwFailures($created);

        $this->waitUntilReady($api, collect($list)->map(fn ($row) => [...$row, 'shipping_document_type' => $type])->all());

        $pdf = $api->download('/api/v2/logistics/download_shipping_document', [
            'shipping_document_type' => $type,
            'order_list' => $list,
        ]);

        SalesOrder::withoutGlobalScope('warehouse_access')->whereKey($orders->modelKeys())->update(['shipping_label_printed_at' => now()]);

        return $pdf;
    }

    public function fetchTracking(SalesOrder $order, ShopeeShopApi $api): bool
    {
        try {
            $tracking = $api->get('/api/v2/logistics/get_tracking_number', array_filter([
                'order_sn' => $order->external_number,
                'package_number' => $order->shopee_package_number,
            ]))['response']['tracking_number'] ?? null;
        } catch (ShopeeException) {
            return false; // Resi belum terbit; diambil lagi saat sinkron berikutnya.
        }

        if (filled($tracking)) {
            $order->update(['tracking_number' => $tracking]);

            return true;
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $list
     */
    private function waitUntilReady(ShopeeShopApi $api, array $list): void
    {
        $attempts = max(1, (int) config('shopee.document_poll_attempts', 8));

        for ($i = 0; $i < $attempts; $i++) {
            $results = collect($api->post('/api/v2/logistics/get_shipping_document_result', ['order_list' => $list])['response']['result_list'] ?? []);
            $this->throwFailures($results);

            if ($results->isNotEmpty() && $results->every(fn ($row) => ($row['status'] ?? '') === 'READY')) {
                return;
            }

            if ($failed = $results->firstWhere('status', 'FAILED')) {
                throw new ShopeeException('Shopee gagal membuat label untuk '.$failed['order_sn'].'.');
            }

            usleep(max(0, (int) config('shopee.document_poll_ms', 1000)) * 1000);
        }

        throw new ShopeeException('Label resi masih diproses Shopee. Coba cetak lagi sebentar lagi.');
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function throwFailures(Collection $rows): void
    {
        $failed = $rows->first(fn ($row) => filled($row['fail_error'] ?? null));

        if ($failed) {
            throw new ShopeeException('Label '.$failed['order_sn'].': '.($failed['fail_message'] ?? $failed['fail_error']), $failed['fail_error']);
        }
    }

    /** @param Collection<int, array<string, mixed>> $params */
    private function documentType(Collection $params): string
    {
        $common = $params->reduce(
            fn (?array $carry, $row) => $carry === null ? ($row['selectable_shipping_document_type'] ?? []) : array_values(array_intersect($carry, $row['selectable_shipping_document_type'] ?? [])),
        ) ?? [];

        $preferred = (string) config('shopee.label_type', 'THERMAL_AIR_WAYBILL');

        foreach ([$preferred, ...self::DOCUMENT_TYPES] as $type) {
            if (in_array($type, $common, true)) {
                return $type;
            }
        }

        return $params->first()['suggest_shipping_document_type'] ?? $preferred;
    }

    private function ensureShopee(SalesOrder $order): void
    {
        if (! $order->isShopee() || ! $order->shopeeShop) {
            throw ValidationException::withMessages(['order' => "{$order->number} bukan pesanan Shopee."]);
        }
    }

    private function ensureArrangeable(SalesOrder $order): void
    {
        $this->ensureShopee($order);

        if (! $order->canArrangeShipment()) {
            throw ValidationException::withMessages(['order' => $order->shopeeShipmentArranged()
                ? "Pengiriman {$order->number} sudah diatur di Shopee."
                : "{$order->number} belum bisa diatur pengirimannya (status {$order->statusLabel()} / Shopee {$order->shopee_order_status})."]);
        }
    }

    private function api(SalesOrder $order): ShopeeShopApi
    {
        return ShopeeShopApi::for($order->shopeeShop);
    }

    /**
     * @param  array<mixed>  $needs
     * @return list<string>
     */
    private function needs(array $needs): array
    {
        return array_values(array_map(fn ($need) => $need === 'tracking_no' ? 'tracking_number' : (string) $need, $needs));
    }

    /** @param array{needs: list<string>} $available */
    private function needsTracking(array $available): bool
    {
        return in_array('tracking_number', $available['needs'], true);
    }

    /** @param array<string, mixed> $input */
    private function requiredTracking(array $input): string
    {
        if (blank($input['tracking_number'] ?? null)) {
            throw ValidationException::withMessages(['tracking_number' => 'Isi nomor resi dari kurir.']);
        }

        return trim($input['tracking_number']);
    }

    /** @param array<string, mixed> $address */
    private function formatAddress(array $address): string
    {
        return collect([$address['address'] ?? null, $address['town'] ?? null, $address['district'] ?? null, $address['city'] ?? null, $address['state'] ?? null, $address['zipcode'] ?? null])
            ->filter(fn ($part) => filled($part))
            ->implode(', ');
    }

    /** @param array<string, mixed> $slot */
    private function slotLabel(array $slot): string
    {
        $date = isset($slot['date']) ? Carbon::createFromTimestamp((int) $slot['date'], config('app.timezone'))->translatedFormat('l, d M Y') : null;

        return trim(implode(' · ', array_filter([$date, $slot['time_text'] ?? null]))) ?: (string) $slot['pickup_time_id'];
    }

    private function methodName(string $method): string
    {
        return SalesOrder::SHIPPING_METHODS[$method]['label'] ?? $method;
    }
}
