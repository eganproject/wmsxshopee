<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\SalesOrder;
use App\Services\Shopee\ShopeeException;
use App\Services\Shopee\ShopeeLogistics;
use App\Services\Tiktok\TiktokException;
use App\Services\Tiktok\TiktokLogistics;
use App\Support\Activity;
use App\Support\Barcode;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Atur pengiriman (pickup / drop off) dan cetak label resi.
 *
 * Pesanan Shopee & TikTok diatur lewat API logistik platformnya; pesanan manual cukup
 * dicatat (jadwal jemput kurir atau titik drop off) supaya tim gudang tahu
 * paket mana yang dijemput dan mana yang harus diantar.
 */
class ShipmentController extends Controller
{
    public function create(SalesOrder $order, ShopeeLogistics $logistics, TiktokLogistics $tiktok): View|RedirectResponse
    {
        if (! $order->canArrangeShipment()) {
            return redirect()->route('sales.orders.show', $order)->with('error', match (true) {
                $order->shopeeShipmentArranged() => "Pengiriman {$order->number} sudah diatur di Shopee.",
                $order->tiktokShipmentArranged() => "Pengiriman {$order->number} sudah diatur di TikTok.",
                $order->isTiktok() && $order->tiktok_cancel_requested => "Pembeli {$order->number} mengajukan pembatalan di TikTok. Tunggu keputusan di Seller Center.",
                default => "Pengiriman {$order->number} bisa diatur setelah pesanan dikonfirmasi dan sebelum dikirim.",
            });
        }

        $order->load(['warehouse', 'courier', 'shopeeShop', 'tiktokShop']);
        $options = null;
        $error = null;

        if ($order->isShopee()) {
            try {
                $options = $logistics->options($order);
            } catch (ShopeeException $e) {
                $error = $e->getMessage();
            }
        } elseif ($order->isTiktok()) {
            try {
                $options = $tiktok->options($order);
            } catch (TiktokException|ValidationException $e) {
                $error = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
            }
        }

        return view('sales.orders.shipment', [
            'order' => $order,
            'options' => $options,
            'shopeeError' => $error,
            'couriers' => Courier::query()
                ->where(fn ($q) => $q->where('is_active', true)->when($order->courier_id, fn ($q) => $q->orWhere('id', $order->courier_id)))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, SalesOrder $order, ShopeeLogistics $logistics, TiktokLogistics $tiktok): RedirectResponse
    {
        abort_unless($order->canArrangeShipment(), 403, 'Pengiriman pesanan ini tidak bisa diatur.');

        if ($order->isTiktok()) {
            $data = $request->validate([
                'method' => ['required', Rule::in(['pickup', 'dropoff'])],
                'pickup_slot' => ['nullable', 'string', 'max:50'],
            ], [], ['method' => 'cara kirim', 'pickup_slot' => 'jadwal pickup']);

            try {
                Activity::track($order, fn () => $tiktok->arrange($order, $data['method'], $data, $request->user()->id));
            } catch (TiktokException $e) {
                return back()->withInput()->with('error', $e->getMessage());
            }

            $order->refresh();

            return redirect()->route('sales.orders.show', $order)->with('success', "Pengiriman {$order->number} diatur di TikTok ({$order->shippingMethodLabel()}).".($order->tracking_number ? " Resi {$order->tracking_number}." : ' Resi terisi otomatis begitu terbit.'));
        }

        if ($order->isShopee()) {
            $data = $request->validate([
                'method' => ['required', Rule::in(['pickup', 'dropoff', 'non_integrated'])],
                'address_id' => ['nullable', 'integer'],
                'pickup_time_id' => ['nullable', 'string', 'max:100'],
                'branch_id' => ['nullable', 'integer'],
                'sender_real_name' => ['nullable', 'string', 'max:100'],
                'slug' => ['nullable', 'string', 'max:50'],
                'tracking_number' => ['nullable', 'string', 'max:100'],
            ], [], ['method' => 'cara kirim']);

            try {
                Activity::track($order, fn () => $logistics->arrange($order, $data['method'], $data, $request->user()->id));
            } catch (ShopeeException $e) {
                return back()->withInput()->with('error', $e->getMessage());
            }

            $order->refresh();

            return redirect()->route('sales.orders.show', $order)->with('success', "Pengiriman {$order->number} diatur di Shopee ({$order->shippingMethodLabel()}).".($order->tracking_number ? " Resi {$order->tracking_number}." : ' Resi terisi otomatis begitu terbit.'));
        }

        $data = $request->validate([
            'shipping_method' => ['required', Rule::in(array_keys(SalesOrder::SHIPPING_METHODS))],
            'pickup_at' => ['nullable', 'required_if:shipping_method,pickup', 'date'],
            'pickup_address' => ['nullable', 'string', 'max:1000'],
            'dropoff_point' => ['nullable', 'required_if:shipping_method,dropoff', 'string', 'max:255'],
            'courier_id' => ['nullable', 'integer', Rule::exists('couriers', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $order->courier_id))],
            'courier_service' => ['nullable', 'string', 'max:50'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
        ], [], [
            'shipping_method' => 'cara kirim',
            'pickup_at' => 'jadwal pickup',
            'pickup_address' => 'alamat pickup',
            'dropoff_point' => 'lokasi drop off',
            'courier_id' => 'kurir',
            'courier_service' => 'layanan',
            'tracking_number' => 'nomor resi',
        ]);

        $pickup = $data['shipping_method'] === 'pickup';

        Activity::track($order, fn () => $order->update([
            'shipping_method' => $data['shipping_method'],
            'pickup_at' => $pickup ? Date::parse($data['pickup_at']) : null,
            'pickup_slot' => null,
            'pickup_address' => $pickup ? ($data['pickup_address'] ?? null) : null,
            'dropoff_point' => $pickup ? null : $data['dropoff_point'],
            'courier_id' => $data['courier_id'] ?? $order->courier_id,
            'courier_service' => $data['courier_service'] ?? $order->courier_service,
            'tracking_number' => filled($data['tracking_number'] ?? null) ? trim($data['tracking_number']) : $order->tracking_number,
            'shipment_arranged_at' => now(),
            'shipment_arranged_by' => $request->user()->id,
        ]));

        return redirect()->route('sales.orders.show', $order)->with('success', "Pengiriman {$order->number} dicatat: {$order->shippingMethodLabel()}.");
    }

    /** Atur banyak pesanan Shopee & TikTok sekaligus dengan pilihan otomatis. */
    public function bulk(Request $request, ShopeeLogistics $logistics, TiktokLogistics $tiktok): RedirectResponse
    {
        $data = $request->validate([
            'orders' => ['required', 'array', 'max:100'],
            'orders.*' => ['integer'],
            'method' => ['required', Rule::in(array_keys(SalesOrder::SHIPPING_METHODS))],
        ], ['orders.required' => 'Pilih pesanan yang akan diatur pengirimannya.']);

        $orders = SalesOrder::with(['shopeeShop', 'tiktokShop', 'warehouse'])->whereKey($data['orders'])->orderBy('id')->get();
        $manual = $orders->reject->isMarketplace();

        $shopee = $logistics->arrangeMany($orders->filter->isShopee(), $data['method'], $request->user()->id);
        $tiktokResult = $tiktok->arrangeMany($orders->filter->isTiktok(), $data['method'], $request->user()->id);
        $result = ['arranged' => [...$shopee['arranged'], ...$tiktokResult['arranged']], 'failed' => $shopee['failed'] + $tiktokResult['failed']];

        $label = SalesOrder::SHIPPING_METHODS[$data['method']]['label'];
        $messages = [];
        if ($shopee['arranged']) {
            $messages[] = count($shopee['arranged'])." pesanan diatur {$label} di Shopee.";
        }
        if ($tiktokResult['arranged']) {
            $messages[] = count($tiktokResult['arranged'])." pesanan diatur {$label} di TikTok.";
        }
        if ($manual->isNotEmpty()) {
            $messages[] = 'Pesanan manual ('.$manual->pluck('number')->implode(', ').') diatur satu per satu dari halaman pesanan.';
        }

        $redirect = back()->with($result['arranged'] ? 'success' : 'error', implode(' ', $messages) ?: 'Tidak ada pesanan yang diatur.');

        if ($result['failed']) {
            $redirect->with('shipment_failures', $result['failed']);
        }

        return $redirect;
    }

    public function label(SalesOrder $order, ShopeeLogistics $logistics, TiktokLogistics $tiktok): Response|View|RedirectResponse
    {
        if ($order->isTiktok()) {
            $order->load('tiktokShop');

            if (! $order->hasShippingLabel()) {
                return back()->with('error', 'Label resi TikTok tersedia setelah pengiriman diatur.');
            }

            try {
                $pdf = $tiktok->label($order);
            } catch (TiktokException|ValidationException $e) {
                return back()->with('error', $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage());
            }

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="resi-'.$order->external_number.'.pdf"',
            ]);
        }

        return $this->labels(new EloquentCollection([$order]), $logistics);
    }

    public function bulkLabel(Request $request, ShopeeLogistics $logistics, TiktokLogistics $tiktok): Response|View|RedirectResponse
    {
        $data = $request->validate([
            'orders' => ['required', 'array', 'max:'.ShopeeLogistics::MAX_DOCUMENTS],
            'orders.*' => ['integer'],
        ], ['orders.required' => 'Pilih pesanan yang labelnya akan dicetak.', 'orders.max' => 'Maksimal :max label sekali cetak.']);

        $orders = SalesOrder::whereKey($data['orders'])->orderBy('id')->get();

        // Label TikTok berupa PDF per paket dari TikTok: satu pesanan dicetak langsung,
        // lebih dari satu dicetak dari halaman pesanan masing-masing.
        if ($orders->contains->isTiktok()) {
            if ($orders->count() === 1) {
                return $this->label($orders->first(), $logistics, $tiktok);
            }

            return back()->with('error', 'Label TikTok dicetak per pesanan (tombol Cetak Label di halaman pesanan). Pilih pesanan Shopee atau manual saja untuk cetak massal.');
        }

        return $this->labels($orders, $logistics);
    }

    /** @param EloquentCollection<int, SalesOrder> $orders */
    private function labels(EloquentCollection $orders, ShopeeLogistics $logistics): Response|View|RedirectResponse
    {
        $orders->load(['warehouse', 'courier', 'shopeeShop', 'items.variant']);
        $printable = $orders->filter->hasShippingLabel();

        if ($printable->isEmpty()) {
            return back()->with('error', $orders->contains->isShopee()
                ? 'Label resi Shopee tersedia setelah pengiriman diatur.'
                : 'Atur pengiriman atau isi resi dulu sebelum mencetak label.');
        }

        if ($printable->contains->isShopee() && $printable->contains(fn ($order) => ! $order->isShopee())) {
            return back()->with('error', 'Cetak label Shopee dan label pesanan manual secara terpisah.');
        }

        if (! $printable->first()->isShopee()) {
            $printable->each->update(['shipping_label_printed_at' => now()]);

            return view('sales.orders.shipping-label', [
                'orders' => $printable,
                'barcodes' => $printable->mapWithKeys(fn ($order) => [$order->id => $order->tracking_number ? Barcode::code128($order->tracking_number) : null]),
            ]);
        }

        try {
            $pdf = $logistics->label($printable);
        } catch (ShopeeException|ValidationException $e) {
            $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();

            return back()->with('error', $message);
        }

        $name = $printable->count() === 1 ? 'resi-'.$printable->first()->external_number : 'resi-shopee-'.now()->format('Ymd-His');

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'.pdf"',
        ]);
    }
}
