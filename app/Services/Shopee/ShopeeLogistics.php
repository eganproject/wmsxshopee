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

        $api = $this->api($order);
        $response = ShopeePackage::call($order, fn (array $package) => $api->get('/api/v2/logistics/get_shipping_parameter', [
            'order_sn' => $order->external_number,
            ...$package,
        ]))['response'] ?? [];

        return $this->parseOptions($response);
    }

    /**
     * @param  array<string, mixed>  $response  isi "response" dari get_shipping_parameter
     * @return array<string, mixed>
     */
    private function parseOptions(array $response): array
    {
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
        [$payload, $record] = $this->prepare($order, $method, $input, $options);

        $api = $this->api($order);
        ShopeePackage::call($order, fn (array $package) => $api->post('/api/v2/logistics/ship_order', $this->shipBody($order, $method, $payload, $package)));

        $this->markArranged($order, $payload, $record, $userId);
        $this->fetchTracking($order, $api);
    }

    /**
     * Isian ship_order & catatan pesanan untuk cara kirim yang dipilih.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $options
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function prepare(SalesOrder $order, string $method, array $input, array $options): array
    {
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

        return [$payload, $record];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $package
     * @return array<string, mixed>
     */
    private function shipBody(SalesOrder $order, string $method, array $payload, array $package): array
    {
        return [
            'order_sn' => $order->external_number,
            ...$package,
            $method => $payload === [] ? new \stdClass : $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $record
     */
    private function markArranged(SalesOrder $order, array $payload, array $record, ?int $userId): void
    {
        $order->update([
            ...$record,
            'shopee_order_status' => 'PROCESSED',
            'shopee_sync_error' => null,
            'shipment_arranged_at' => now(),
            'shipment_arranged_by' => $userId,
            'tracking_number' => $payload['tracking_number'] ?? $order->tracking_number,
        ]);
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
        $failed = [];
        $arranged = [];
        $ready = collect();

        foreach ($orders as $order) {
            try {
                $this->ensureArrangeable($order);
                $ready->push($order);
            } catch (ValidationException $e) {
                $failed[$order->number] = collect($e->errors())->flatten()->first();
            }
        }

        // Per toko: ambil pilihan kirim semua pesanan sekaligus, lalu atur semuanya sekaligus
        // (paralel), bukan tiga panggilan berurutan per pesanan.
        foreach ($ready->groupBy('shopee_shop_id') as $shopOrders) {
            try {
                $api = $this->api($shopOrders->first());
                $parameters = $this->callMany($api, $shopOrders, 'GET', '/api/v2/logistics/get_shipping_parameter', fn (SalesOrder $order, array $package) => [
                    'order_sn' => $order->external_number,
                    ...$package,
                ]);

                $planned = [];
                foreach ($shopOrders as $order) {
                    try {
                        $response = $parameters[$order->id];
                        if ($response instanceof ShopeeException) {
                            throw $response;
                        }
                        $options = $this->parseOptions($response['response'] ?? []);
                        $planned[$order->id] = $this->prepare($order, $method, $this->autoInput($order, $method, $options), $options);
                    } catch (ValidationException $e) {
                        $failed[$order->number] = collect($e->errors())->flatten()->first();
                    } catch (ShopeeException $e) {
                        $failed[$order->number] = $e->getMessage();
                    }
                }

                $toShip = $shopOrders->filter(fn (SalesOrder $order) => isset($planned[$order->id]))->values();
                $shipped = $this->callMany($api, $toShip, 'POST', '/api/v2/logistics/ship_order', fn (SalesOrder $order, array $package) => $this->shipBody($order, $method, $planned[$order->id][0], $package));

                $done = collect();
                foreach ($toShip as $order) {
                    if ($shipped[$order->id] instanceof ShopeeException) {
                        $failed[$order->number] = $shipped[$order->id]->getMessage();

                        continue;
                    }

                    $this->markArranged($order, $planned[$order->id][0], $planned[$order->id][1], $userId);
                    $done->push($order);
                }

                $this->fetchTrackingMany($api, $done);
            } catch (ShopeeException $e) {
                // Gangguan tingkat toko (cth. toko perlu diotorisasi ulang).
                foreach ($shopOrders as $order) {
                    if (! $order->shipment_arranged_at && ! isset($failed[$order->number])) {
                        $failed[$order->number] = $e->getMessage();
                    }
                }
            }
        }

        // Urutan hasil mengikuti urutan pesanan yang dipilih.
        foreach ($orders as $order) {
            if (! isset($failed[$order->number]) && $ready->contains($order) && $order->shipment_arranged_at) {
                $arranged[] = $order->number;
            }
        }

        return ['arranged' => $arranged, 'failed' => $failed];
    }

    /**
     * Pilihan otomatis untuk atur massal: alamat pickup Shopee (atau default) dan slot jam
     * yang disarankan / paling awal; drop off tanpa memilih cabang.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function autoInput(SalesOrder $order, string $method, array $options): array
    {
        if ($method === 'pickup' && ($pickup = $options['pickup'])) {
            $address = $pickup['addresses'][0] ?? null;
            $slots = collect($address['slots'] ?? []);
            $slot = $slots->firstWhere('recommended', true) ?? $slots->first();

            return ['address_id' => $address['address_id'] ?? null, 'pickup_time_id' => $slot['pickup_time_id'] ?? null];
        }

        if ($method === 'dropoff' && ($dropoff = $options['dropoff'])) {
            $extra = array_diff($dropoff['needs'], ['sender_real_name']);
            if ($extra !== []) {
                throw ValidationException::withMessages(['method' => 'Drop off kurir ini perlu memilih cabang; atur dari halaman pesanan.']);
            }

            return ['sender_real_name' => $order->warehouse?->pic_name ?: $order->warehouse?->name];
        }

        return [];
    }

    /**
     * Panggil satu API logistik untuk banyak pesanan dari toko yang sama sekaligus (paralel).
     * Pesanan yang ditolak karena package_number padahal tidak dipecah diulang tanpa nomor
     * paket, seperti ShopeePackage::call().
     *
     * @param  Collection<int, SalesOrder>  $orders
     * @param  callable(SalesOrder, array<string, string>): array<string, mixed>  $params
     * @return array<int, array<string, mixed>|ShopeeException> per id pesanan
     */
    private function callMany(ShopeeShopApi $api, Collection $orders, string $method, string $path, callable $params): array
    {
        $package = fn (SalesOrder $order) => filled($order->shopee_package_number) ? ['package_number' => (string) $order->shopee_package_number] : [];

        $results = $api->many($orders->mapWithKeys(fn (SalesOrder $order) => [$order->id => [
            'method' => $method,
            'path' => $path,
            'params' => $params($order, $package($order)),
        ]])->all());

        foreach ($orders as $order) {
            $result = $results[$order->id] ?? null;

            if ($result instanceof ShopeeException && filled($order->shopee_package_number) && ShopeePackage::isUnsplitError($result)) {
                $order->update(['shopee_package_number' => null]);

                try {
                    $results[$order->id] = $method === 'GET' ? $api->get($path, $params($order, [])) : $api->post($path, $params($order, []));
                } catch (ShopeeException $e) {
                    $results[$order->id] = $e;
                }
            }
        }

        return $results;
    }

    /**
     * Ambil resi banyak pesanan sekaligus; yang belum terbit dibiarkan kosong.
     *
     * @param  Collection<int, SalesOrder>  $orders
     */
    private function fetchTrackingMany(ShopeeShopApi $api, Collection $orders): void
    {
        if ($orders->isEmpty()) {
            return;
        }

        try {
            $results = $this->callMany($api, $orders, 'GET', '/api/v2/logistics/get_tracking_number', fn (SalesOrder $order, array $package) => [
                'order_sn' => $order->external_number,
                ...$package,
            ]);
        } catch (ShopeeException) {
            return; // Resi diambil lagi saat sinkron berikutnya.
        }

        foreach ($orders as $order) {
            $tracking = is_array($results[$order->id] ?? null) ? ($results[$order->id]['response']['tracking_number'] ?? null) : null;

            if (filled($tracking)) {
                $order->update(['tracking_number' => $tracking]);
            }
        }
    }

    /**
     * Unduh label resi Shopee (PDF) untuk satu atau beberapa pesanan dari toko yang sama.
     * Hanya dipakai bila semua label muat dalam satu PDF; untuk banyak pesanan pakai documents().
     *
     * @param  Collection<int, SalesOrder>  $orders
     */
    public function label(Collection $orders): string
    {
        $documents = $this->documents($orders);

        if (count($documents) > 1) {
            throw new ShopeeException('Shopee tidak bisa menyatukan label pesanan ini dalam satu PDF. Gunakan cetak resi gabungan.');
        }

        return $documents[0];
    }

    /**
     * Unduh label resi Shopee untuk pesanan dari toko yang sama, dalam satu PDF atau lebih.
     * Shopee menolak mengunduh bersamaan paket yang tidak cocok (mis. beda kurir atau beda
     * cara kirim): pesanan dikelompokkan dulu per kurir & cara kirim, lalu kelompok yang
     * masih ditolak Shopee diunduh satu per satu.
     *
     * @param  Collection<int, SalesOrder>  $orders
     * @return list<string>
     */
    public function documents(Collection $orders): array
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
        $this->fetchTrackingMany($api, $orders->filter(fn (SalesOrder $order) => blank($order->tracking_number))->values());

        // Shopee baru bisa membuat label setelah nomor resi terbit (beberapa saat setelah pengiriman diatur).
        $waiting = $orders->filter(fn (SalesOrder $order) => blank($order->tracking_number))->pluck('number');
        if ($waiting->isNotEmpty()) {
            throw ValidationException::withMessages(['orders' => 'Nomor resi dari Shopee belum terbit untuk '.$waiting->take(5)->implode(', ').($waiting->count() > 5 ? ' dan '.($waiting->count() - 5).' lainnya' : '').'. Tunggu beberapa menit lalu cetak lagi.']);
        }

        // Semua kelompok diproses bersamaan; kelompok yang ditolak Shopee diunduh per pesanan,
        // juga bersamaan, bukan satu per satu.
        $groups = self::compatibleGroups($orders)->all();
        $results = $this->downloadMany($api, $groups);
        $singles = [];

        foreach ($groups as $i => $group) {
            $result = $results[$i];
            if (! $result instanceof ShopeeException) {
                continue;
            }
            if ($result->shopeeError !== 'logistics.packages_can_not_download_together' || $group->count() === 1) {
                throw $result;
            }
            foreach ($group as $order) {
                $singles[$i][] = collect([$order]);
            }
        }

        $singleResults = $this->downloadMany($api, collect($singles)->flatten(1)->all());
        foreach ($singleResults as $result) {
            if ($result instanceof ShopeeException) {
                throw $result;
            }
        }

        $documents = [];
        foreach ($groups as $i => $group) {
            if (isset($singles[$i])) {
                foreach ($singles[$i] as $_) {
                    $documents[] = array_shift($singleResults);
                }
            } else {
                $documents[] = $results[$i];
            }
        }

        SalesOrder::withoutGlobalScope('warehouse_access')->whereKey($orders->modelKeys())->update(['shipping_label_printed_at' => now()]);

        return $documents;
    }

    /**
     * Kelompok pesanan yang kemungkinan besar boleh diunduh Shopee dalam satu PDF.
     *
     * @param  Collection<int, SalesOrder>  $orders
     * @return Collection<int, Collection<int, SalesOrder>>
     */
    public static function compatibleGroups(Collection $orders): Collection
    {
        return $orders->groupBy(fn (SalesOrder $order) => ($order->courier_id ?? '-').'|'.($order->shipping_method ?? '-'))->map->values()->values();
    }

    /**
     * Buat & unduh label untuk beberapa kelompok pesanan sekaligus. Tiap tahap
     * (parameter, buat label, cek status, unduh) dikirim paralel untuk semua kelompok.
     *
     * @param  list<Collection<int, SalesOrder>>  $groups
     * @return list<string|ShopeeException> PDF atau error per kelompok, urutan sama dengan $groups
     */
    private function downloadMany(ShopeeShopApi $api, array $groups): array
    {
        $results = [];
        $lists = [];
        foreach ($groups as $i => $group) {
            $lists[$i] = $group->map(fn (SalesOrder $order) => array_filter([
                'order_sn' => $order->external_number,
                'package_number' => $order->shopee_package_number,
            ]))->values()->all();
        }

        // Kelompok yang masih berjalan; yang gagal langsung diisi error-nya.
        $step = function (string $method, string $path, callable $params, array $skip = []) use ($api, &$results, $groups) {
            $active = array_diff_key($groups, $results, $skip);
            $responses = $api->many(array_map(fn ($i) => ['method' => $method, 'path' => $path, 'params' => $params($i)], array_combine(array_keys($active), array_keys($active))));

            foreach ($responses as $i => $response) {
                if ($response instanceof ShopeeException) {
                    $results[$i] = $response;
                }
            }

            return array_filter($responses, fn ($response) => ! $response instanceof ShopeeException);
        };
        $rows = function (array $responses, callable $each) use (&$results) {
            foreach ($responses as $i => $response) {
                try {
                    $each($i, collect($response['response']['result_list'] ?? []));
                } catch (ShopeeException $e) {
                    $results[$i] = $e;
                }
            }
        };

        $types = [];
        $rows($step('POST', '/api/v2/logistics/get_shipping_document_parameter', fn ($i) => ['order_list' => $lists[$i]]), function ($i, Collection $params) use (&$types) {
            $this->throwFailures($params);
            $types[$i] = $this->documentType($params);
        });

        $rows($step('POST', '/api/v2/logistics/create_shipping_document', fn ($i) => [
            'order_list' => $groups[$i]->map(fn (SalesOrder $order) => array_filter([
                'order_sn' => $order->external_number,
                'package_number' => $order->shopee_package_number,
                'tracking_number' => $order->tracking_number,
                'shipping_document_type' => $types[$i],
            ]))->values()->all(),
        ]), fn ($i, Collection $created) => $this->throwFailures($created));

        // Label dibuat Shopee secara asinkron: cek status semua kelompok, tunggu yang belum siap.
        $attempts = max(1, (int) config('shopee.document_poll_attempts', 8));
        $ready = [];
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $rows($step('POST', '/api/v2/logistics/get_shipping_document_result', fn ($i) => [
                'order_list' => array_map(fn ($row) => [...$row, 'shipping_document_type' => $types[$i]], $lists[$i]),
            ], $ready), function ($i, Collection $status) use (&$ready) {
                $this->throwFailures($status);

                if ($failed = $status->firstWhere('status', 'FAILED')) {
                    throw new ShopeeException('Shopee gagal membuat label untuk '.$failed['order_sn'].'.');
                }
                if ($status->isNotEmpty() && $status->every(fn ($row) => ($row['status'] ?? '') === 'READY')) {
                    $ready[$i] = true;
                }
            });

            if (array_diff_key($groups, $results, $ready) === []) {
                break;
            }

            usleep(max(0, (int) config('shopee.document_poll_ms', 1000)) * 1000);
        }

        foreach (array_diff_key($groups, $results, $ready) as $i => $_) {
            $results[$i] = new ShopeeException('Label resi masih diproses Shopee. Coba cetak lagi sebentar lagi.');
        }

        foreach ($step('DOWNLOAD', '/api/v2/logistics/download_shipping_document', fn ($i) => [
            'shipping_document_type' => $types[$i],
            'order_list' => $lists[$i],
        ]) as $i => $pdf) {
            $results[$i] = $pdf;
        }

        ksort($results);

        return array_values($results);
    }

    public function fetchTracking(SalesOrder $order, ShopeeShopApi $api): bool
    {
        try {
            $tracking = ShopeePackage::call($order, fn (array $package) => $api->get('/api/v2/logistics/get_tracking_number', [
                'order_sn' => $order->external_number,
                ...$package,
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
