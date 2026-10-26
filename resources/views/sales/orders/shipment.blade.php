@php
    $shopee = $order->isShopee();
    $tiktok = $order->isTiktok();
    $marketplace = $shopee || $tiktok;
    $platformName = $shopee ? 'Shopee' : 'TikTok';
    $platformError = $shopeeError;
    $methods = [];

    if ($tiktok && $options) {
        foreach (['pickup', 'dropoff'] as $key) {
            if ($options[$key]) {
                $methods[$key] = \App\Models\SalesOrder::SHIPPING_METHODS[$key];
            }
        }
    } elseif ($shopee && $options) {
        foreach (['pickup', 'dropoff'] as $key) {
            if ($options[$key]) {
                $methods[$key] = \App\Models\SalesOrder::SHIPPING_METHODS[$key];
            }
        }
        if ($options['non_integrated']) {
            $methods['non_integrated'] = ['label' => 'Resi Sendiri', 'description' => 'Kurir di luar integrasi Shopee; isi resi dari kurir', 'icon' => 'heroicon-o-qr-code'];
        }
    } elseif (! $marketplace) {
        $methods = \App\Models\SalesOrder::SHIPPING_METHODS;
    }

    $addresses = $shopee ? ($options['pickup']['addresses'] ?? []) : [];
    $firstAddress = $addresses[0] ?? null;
    $firstSlot = collect($firstAddress['slots'] ?? [])->firstWhere('recommended', true) ?? ($firstAddress['slots'][0] ?? null);
    $needs = fn (string $method, string $field) => in_array($field, $options[$method]['needs'] ?? [], true);
    $tiktokSlots = $tiktok ? ($options['pickup']['slots'] ?? []) : [];

    $warehouse = $order->warehouse;
    $warehouseAddress = collect([$warehouse->address, $warehouse->city, $warehouse->province, $warehouse->postal_code])->filter()->implode(', ');
    $defaultMethod = old($marketplace ? 'method' : 'shipping_method', $order->shipping_method ?? array_key_first($methods));
    $shipBy = $order->shopee_ship_by ?? $order->tiktok_ship_by;
@endphp

<x-layouts.app :title="'Atur Pengiriman '.$order->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.orders.index') }}" class="hover:text-slate-700">Pesanan</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.orders.show', $order) }}" class="hover:text-slate-700">{{ $order->number }}</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Atur Pengiriman</span>
    </x-slot:breadcrumb>

    <x-page-header :title="'Atur Pengiriman '.$order->number"
                   :description="$marketplace ? 'Pilih pickup atau drop off. Pengiriman diatur langsung di '.$platformName.' dan resi terisi otomatis.' : 'Catat apakah paket dijemput kurir atau diantar ke cabang / agen kurir.'"
                   icon="heroicon-o-truck">
        <a href="{{ route('sales.orders.show', $order) }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <div class="card mb-6 grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <p class="text-xs text-slate-500">Pembeli</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $order->customer_name }}</p>
            @if ($order->customer_phone)
                <p class="text-xs text-slate-500">{{ $order->customer_phone }}</p>
            @endif
        </div>
        <div class="lg:col-span-2">
            <p class="text-xs text-slate-500">Alamat Kirim</p>
            <p class="mt-1 text-sm whitespace-pre-line text-slate-700">{{ $order->shipping_address ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500">Kurir</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $order->courier?->name ?? '—' }}{{ $order->courier_service ? ' · '.$order->courier_service : '' }}</p>
            @if ($shipBy)
                <p @class(['text-xs', 'font-medium text-rose-600' => $shipBy->isPast() || $shipBy->lt(now()->addDay()), 'text-slate-500' => $shipBy->gte(now()->addDay())])>
                    Kirim sebelum {{ $shipBy->format('d/m/Y H:i') }}
                </p>
            @endif
        </div>
    </div>

    @if ($marketplace && $platformError)
        <div class="mb-6 flex items-start gap-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            <x-heroicon-o-exclamation-triangle class="mt-0.5 size-5 shrink-0" />
            <div>
                <p class="font-medium">Pilihan pengiriman tidak bisa diambil dari {{ $platformName }}.</p>
                <p class="mt-0.5">{{ $platformError }}</p>
                <a href="{{ route('sales.orders.shipment', $order) }}" class="mt-2 inline-flex items-center gap-1 font-medium underline"><x-heroicon-o-arrow-path class="size-4" /> Coba lagi</a>
            </div>
        </div>
    @elseif ($methods === [])
        <div class="card p-8 text-center text-sm text-slate-500">{{ $platformName }} tidak menyediakan pilihan pengiriman untuk pesanan ini.</div>
    @else
        <form method="POST" action="{{ route('sales.orders.shipment.store', $order) }}" class="max-w-4xl space-y-6"
              x-data="{
                  method: @js($defaultMethod),
                  addresses: @js($addresses),
                  addressId: @js((string) old('address_id', $firstAddress['address_id'] ?? '')),
                  slotId: @js((string) old('pickup_time_id', $firstSlot['pickup_time_id'] ?? '')),
                  tiktokSlot: @js((string) old('pickup_slot', $tiktokSlots[0]['key'] ?? '')),
                  get slots() { return (this.addresses.find(a => String(a.address_id) === this.addressId) || { slots: [] }).slots; },
                  pickAddress(id) {
                      this.addressId = String(id);
                      const slot = this.slots.find(s => s.recommended) || this.slots[0];
                      this.slotId = slot ? String(slot.pickup_time_id) : '';
                  },
              }">
            @csrf
            <x-form-errors />

            <div class="card">
                <div class="border-b border-slate-100 px-5 py-3">
                    <h2 class="text-base font-semibold text-slate-900">Cara <span class="whitespace-nowrap">Kirim <x-hint text="Pickup: kurir menjemput paket di gudang. Drop Off: paket diantar ke cabang / agen kurir. Pilihan untuk Shopee / TikTok mengikuti yang tersedia di toko." /></span></h2>
                </div>
                <div @class(['grid gap-3 p-5', 'sm:grid-cols-2' => count($methods) === 2, 'sm:grid-cols-3' => count($methods) > 2])>
                    @foreach ($methods as $value => $meta)
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3"
                               :class="method === @js($value) ? 'border-slate-900 bg-slate-50' : 'border-slate-200 hover:bg-slate-50'">
                            <input type="radio" name="{{ $marketplace ? 'method' : 'shipping_method' }}" value="{{ $value }}" x-model="method" class="mt-1">
                            <x-dynamic-component :component="$meta['icon']" class="mt-0.5 size-5 shrink-0 text-slate-400" />
                            <span>
                                <span class="block font-medium text-slate-900">{{ $meta['label'] }}</span>
                                <span class="block text-xs text-slate-500">{{ $meta['description'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>

            @if ($tiktok)
                @if ($options['pickup'])
                    <div class="card" x-show="method === 'pickup'" x-cloak>
                        <div class="border-b border-slate-100 px-5 py-3">
                            <h2 class="text-base font-semibold text-slate-900">Jadwal Pickup</h2>
                            <p class="mt-0.5 text-sm text-slate-500">Kurir TikTok menjemput di alamat pickup toko (diatur di TikTok Shop Seller Center).</p>
                        </div>
                        <div class="p-5">
                            @if ($tiktokSlots)
                                <label for="pickup_slot" class="form-label">Jadwal Pickup <span class="text-rose-500">*</span> <x-hint text="Slot waktu jemput yang disediakan marketplace untuk toko ini. Siapkan paket sebelum jam tersebut." /></label>
                                <select id="pickup_slot" name="pickup_slot" x-model="tiktokSlot" class="form-input sm:max-w-md" data-native :disabled="method !== 'pickup'">
                                    @foreach ($tiktokSlots as $slot)
                                        <option value="{{ $slot['key'] }}">{{ $slot['label'] }}</option>
                                    @endforeach
                                </select>
                            @else
                                <p class="text-sm text-slate-600">TikTok tidak meminta jadwal; kurir menjemput sesuai jadwal toko.</p>
                            @endif
                        </div>
                    </div>
                @endif

                @if ($options['dropoff'])
                    <div class="card" x-show="method === 'dropoff'" x-cloak>
                        <div class="border-b border-slate-100 px-5 py-3">
                            <h2 class="text-base font-semibold text-slate-900">Drop Off</h2>
                            <p class="mt-0.5 text-sm text-slate-500">Setelah diatur, antar paket ke titik drop off kurir dan tunjukkan label resi.</p>
                        </div>
                        <div class="p-5 text-sm text-slate-600">
                            @if ($options['dropoff']['url'])
                                <a href="{{ $options['dropoff']['url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-medium text-brand-600 hover:underline">
                                    <x-heroicon-o-map-pin class="size-4" /> Lihat titik drop off terdekat
                                </a>
                            @else
                                Tidak perlu isian tambahan.
                            @endif
                        </div>
                    </div>
                @endif
            @elseif ($shopee)
                @if ($options['pickup'])
                    <div class="card" x-show="method === 'pickup'" x-cloak>
                        <div class="border-b border-slate-100 px-5 py-3">
                            <h2 class="text-base font-semibold text-slate-900">Alamat & Jadwal Pickup</h2>
                            <p class="mt-0.5 text-sm text-slate-500">Alamat diambil dari pengaturan alamat toko di Shopee Seller Centre.</p>
                        </div>
                        <div class="space-y-5 p-5">
                            <div class="space-y-2">
                                @forelse ($addresses as $address)
                                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3"
                                           :class="addressId === @js((string) $address['address_id']) ? 'border-slate-900 bg-slate-50' : 'border-slate-200 hover:bg-slate-50'">
                                        <input type="radio" name="address_id" value="{{ $address['address_id'] }}" :checked="addressId === @js((string) $address['address_id'])"
                                               @change="pickAddress(@js($address['address_id']))" class="mt-1" :disabled="method !== 'pickup'">
                                        <span class="text-sm text-slate-700">
                                            {{ $address['label'] }}
                                            @if (in_array('pickup_address', $address['flags'], true))
                                                <span class="ml-1 rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700">Alamat pickup</span>
                                            @endif
                                            @if (in_array('default_address', $address['flags'], true))
                                                <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">Default</span>
                                            @endif
                                        </span>
                                    </label>
                                @empty
                                    <p class="text-sm text-slate-500">Belum ada alamat pickup. Tambahkan alamat di Shopee Seller Centre › Pengaturan Alamat.</p>
                                @endforelse
                            </div>

                            @if ($needs('pickup', 'pickup_time_id'))
                                <div>
                                    <label for="pickup_time_id" class="form-label">Jadwal Pickup <span class="text-rose-500">*</span> <x-hint text="Slot waktu jemput yang disediakan Shopee untuk alamat pickup toko. Siapkan paket sebelum jam tersebut." /></label>
                                    <select id="pickup_time_id" name="pickup_time_id" x-model="slotId" class="form-input sm:max-w-md" data-native :disabled="method !== 'pickup'">
                                        <template x-for="slot in slots" :key="slot.pickup_time_id">
                                            <option :value="String(slot.pickup_time_id)" x-text="slot.label + (slot.recommended ? ' (disarankan)' : '')"></option>
                                        </template>
                                    </select>
                                    <p class="mt-1 text-xs text-slate-400" x-show="slots.length === 0">Tidak ada jadwal untuk alamat ini.</p>
                                </div>
                            @endif

                            @if ($needs('pickup', 'tracking_number'))
                                <x-input name="tracking_number" id="tracking_pickup" label="Nomor Resi" tip="Nomor resi dari kurir. Scan barcode di label kurir atau ketik manual. Resi ini yang nanti di-scan saat serah terima." required maxlength="100" class="sm:max-w-md" icon="heroicon-o-qr-code" x-bind:disabled="method !== 'pickup'" />
                            @endif
                        </div>
                    </div>
                @endif

                @if ($options['dropoff'])
                    <div class="card" x-show="method === 'dropoff'" x-cloak>
                        <div class="border-b border-slate-100 px-5 py-3">
                            <h2 class="text-base font-semibold text-slate-900">Drop Off</h2>
                            <p class="mt-0.5 text-sm text-slate-500">Setelah diatur, antar paket ke cabang / agen kurir dan tunjukkan label resi.</p>
                        </div>
                        <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                            @if ($needs('dropoff', 'branch_id'))
                                <x-select name="branch_id" label="Cabang Drop Off" tip="Cabang / agen kurir tempat paket akan diantar." required class="sm:col-span-2" x-bind:disabled="method !== 'dropoff'">
                                    <option value="">Pilih cabang</option>
                                    @foreach ($options['dropoff']['branches'] as $branch)
                                        <option value="{{ $branch['branch_id'] }}" @selected((string) old('branch_id') === (string) $branch['branch_id'])>{{ $branch['label'] }}</option>
                                    @endforeach
                                </x-select>
                            @endif
                            @if ($needs('dropoff', 'slug'))
                                <x-select name="slug" label="Kurir" required x-bind:disabled="method !== 'dropoff'">
                                    <option value="">Pilih kurir</option>
                                    @foreach ($options['dropoff']['slugs'] as $slug)
                                        <option value="{{ $slug['slug'] }}" @selected(old('slug') === $slug['slug'])>{{ $slug['label'] }}</option>
                                    @endforeach
                                </x-select>
                            @endif
                            @if ($needs('dropoff', 'sender_real_name'))
                                <x-input name="sender_real_name" label="Nama Pengantar" tip="Nama orang yang mengantar paket ke cabang. Diminta oleh kurir sebagai data pengirim." required maxlength="100" :value="$warehouse->pic_name" x-bind:disabled="method !== 'dropoff'" />
                            @endif
                            @if ($needs('dropoff', 'tracking_number'))
                                <x-input name="tracking_number" id="tracking_dropoff" label="Nomor Resi" tip="Nomor resi dari kurir. Scan barcode di label kurir atau ketik manual. Resi ini yang nanti di-scan saat serah terima." required maxlength="100" icon="heroicon-o-qr-code" x-bind:disabled="method !== 'dropoff'" />
                            @endif
                            @if (! array_intersect(['branch_id', 'slug', 'sender_real_name', 'tracking_number'], $options['dropoff']['needs']))
                                <p class="text-sm text-slate-600 sm:col-span-2">Tidak perlu isian tambahan. Paket bisa diantar ke cabang / agen kurir mana saja.</p>
                            @endif
                        </div>
                    </div>
                @endif

                @if ($options['non_integrated'])
                    <div class="card" x-show="method === 'non_integrated'" x-cloak>
                        <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                            <x-input name="tracking_number" id="tracking_manual" label="Nomor Resi" tip="Kurir di luar integrasi marketplace: isi resi dari kurir supaya marketplace bisa melacak paket." required maxlength="100" icon="heroicon-o-qr-code" x-bind:disabled="method !== 'non_integrated'" />
                        </div>
                    </div>
                @endif
            @else
                <div class="card">
                    <div class="border-b border-slate-100 px-5 py-3">
                        <h2 class="text-base font-semibold text-slate-900" x-text="method === 'pickup' ? 'Jadwal Pickup' : 'Lokasi Drop Off'"></h2>
                    </div>
                    <div class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                        <div x-show="method === 'pickup'" class="space-y-4 sm:col-span-2">
                            <x-input type="datetime-local" name="pickup_at" label="Jadwal Jemput" tip="Perkiraan waktu kurir datang menjemput. Tampil di daftar pesanan sebagai pengingat." required class="sm:max-w-xs"
                                     :value="$order->pickup_at?->format('Y-m-d\TH:i') ?? now()->addHour()->startOfHour()->format('Y-m-d\TH:i')" x-bind:disabled="method !== 'pickup'" />
                            <x-textarea name="pickup_address" label="Alamat Pickup" rows="2" :value="$order->pickup_address ?? $warehouseAddress" hint="Default: alamat gudang pengirim." x-bind:disabled="method !== 'pickup'" />
                        </div>
                        <div x-show="method === 'dropoff'" x-cloak class="sm:col-span-2">
                            <x-input name="dropoff_point" label="Cabang / Agen Kurir" tip="Nama atau alamat cabang / agen tempat paket diantar." required maxlength="255" :value="$order->dropoff_point" placeholder="cth. Agen JNE Jl. Merdeka" x-bind:disabled="method !== 'dropoff'" />
                        </div>
                        <x-select name="courier_id" label="Kurir">
                            <option value="">Pilih kurir</option>
                            @foreach ($couriers as $courier)
                                <option value="{{ $courier->id }}" @selected((string) old('courier_id', $order->courier_id) === (string) $courier->id)>{{ $courier->name }}</option>
                            @endforeach
                        </x-select>
                        <x-input name="courier_service" label="Layanan" :value="$order->courier_service" placeholder="cth. REG" maxlength="50" />
                        <x-input name="tracking_number" label="Nomor Resi" :value="$order->tracking_number" tip="Nomor resi dari kurir. Boleh dikosongkan dulu dan diisi saat kirim." maxlength="100" icon="heroicon-o-qr-code" hint="Boleh diisi nanti saat kirim." />
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <a href="{{ route('sales.orders.show', $order) }}" class="btn-secondary">Batal</a>
                <button type="submit" class="btn-primary"><x-heroicon-o-check class="size-5" /> {{ $marketplace ? 'Atur di '.$platformName : 'Simpan' }}</button>
            </div>
        </form>
    @endif
</x-layouts.app>
