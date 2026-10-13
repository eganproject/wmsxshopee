<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VariantLookupController extends Controller
{
    /** Pencarian varian untuk select AJAX (Tom Select): [{ value, text }]. */
    public function __invoke(Request $request): JsonResponse
    {
        $search = trim($request->string('q')->toString());

        $variants = ProductVariant::query()
            ->with('product:id,name')
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

                $query->where(fn (Builder $query) => $query
                    ->where('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhereHas('product', fn (Builder $query) => $query->where('name', 'like', $like)));
            })
            ->orderBy('sku')
            ->limit(30)
            ->get();

        return response()->json($variants->map(fn (ProductVariant $variant) => [
            'value' => $variant->id,
            'text' => $variant->displayName(),
        ]));
    }
}
