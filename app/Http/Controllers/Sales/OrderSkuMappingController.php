<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Concerns\ResolvesSkuTarget;
use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\ShopeeSkuMapping;
use App\Models\TiktokSkuMapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Petakan SKU marketplace yang belum terhubung langsung dari halaman pesanan. */
class OrderSkuMappingController extends Controller
{
    use ResolvesSkuTarget;

    public function store(Request $request, SalesOrder $order): RedirectResponse
    {
        $request->validate(['mapping_id' => ['required', 'integer'], 'target' => ['required', 'string']], [
            'target.required' => 'Pilih produk / paket Optima untuk SKU ini.',
        ]);

        if ($order->shopee_shop_id) {
            abort_unless($request->user()->can('shopee.mapping'), 403);
            $mapping = ShopeeSkuMapping::where('shopee_shop_id', $order->shopee_shop_id)->findOrFail($request->integer('mapping_id'));
        } elseif ($order->tiktok_shop_id) {
            abort_unless($request->user()->can('tiktok.mapping'), 403);
            $mapping = TiktokSkuMapping::where('tiktok_shop_id', $order->tiktok_shop_id)->findOrFail($request->integer('mapping_id'));
        } else {
            abort(404);
        }

        $mapping->update($this->target($request->input('target')));
        $sku = $mapping->shopee_sku ?? $mapping->seller_sku ?? 'SKU';
        $note = $this->relinkOrders();

        $order->refresh();
        if ($order->hasStatus('draft')) {
            $problem = $order->shopee_sync_error ?? $order->tiktok_sync_error;

            return redirect()->route('sales.orders.show', $order)
                ->with($problem ? 'error' : 'success', "{$sku} dipetakan.".$note.($problem ? ' Pesanan ini masih draft: '.$problem : ''));
        }

        return redirect()->route('sales.orders.show', $order)->with('success', "{$sku} dipetakan.{$note}");
    }
}
