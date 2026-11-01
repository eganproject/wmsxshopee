<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\StockCount;
use App\Models\WarehouseLocation;
use App\Services\StockCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Stock opname dari HP: pilih lembar opname (dibuat di web), scan rak, hitung
 * barangnya. Hanya baris rak yang dihitung yang dikirim, jadi beberapa petugas
 * bisa menghitung rak berbeda di lembar yang sama. Posting tetap butuh opname.post.
 */
class OpnameController extends Controller
{
    use MobileContext;

    public function index(Request $request): View
    {
        $counts = StockCount::query()
            ->with('zone')
            ->withCount(['items', 'items as counted_count' => fn ($q) => $q->whereNotNull('counted_qty')])
            ->where('warehouse_id', $this->mobileWarehouseId($request))
            ->where('status', 'draft')
            ->latest('id')
            ->get();

        return view('mobile.opname', ['counts' => $counts, 'warehouse' => $this->mobileWarehouse($request)]);
    }

    public function show(StockCount $count): View
    {
        abort_unless($count->isDraft(), 404);

        return view('mobile.opname-count', ['count' => $count->load('zone')]);
    }

    public function lines(StockCount $count): JsonResponse
    {
        $count->load(['items.variant.product', 'items.location']);

        return response()->json([
            'number' => $count->number,
            'draft' => $count->isDraft(),
            'lines' => $count->items->map(fn ($item) => [
                'id' => $item->id,
                'variant_id' => $item->product_variant_id,
                'sku' => $item->variant->sku,
                'barcode' => $item->variant->barcode,
                'name' => $item->variant->fullName(),
                'location_id' => $item->warehouse_location_id,
                'location' => $item->location?->code,
                'system_qty' => (int) $item->system_qty,
                'counted_qty' => $item->counted_qty,
            ])->values(),
        ]);
    }

    public function save(Request $request, StockCount $count, StockCountService $service): JsonResponse
    {
        $post = $request->input('action') === 'post';
        abort_if($post && ! $request->user()->can('opname.post'), 403);

        $location = fn () => Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $count->warehouse_id);
        $lines = Validator::make($request->all(), [
            'lines' => ['required', 'array', 'max:2000'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.product_variant_id' => ['required_without:lines.*.id', 'nullable', 'integer', Rule::exists('product_variants', 'id')],
            'lines.*.warehouse_location_id' => ['nullable', 'integer', $location()],
            'lines.*.counted_qty' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'lines.*.notes' => ['nullable', 'string', 'max:255'],
        ], [], [
            'lines.*.product_variant_id' => 'barang',
            'lines.*.warehouse_location_id' => 'lokasi',
            'lines.*.counted_qty' => 'hasil hitung',
        ])->validate()['lines'];

        foreach ($lines as $index => $line) {
            if (empty($line['id'])) {
                $bin = filled($line['warehouse_location_id'] ?? null) ? WarehouseLocation::with('rack')->find($line['warehouse_location_id']) : null;
                if (! $count->coversLocation($bin)) {
                    throw ValidationException::withMessages(["lines.{$index}.warehouse_location_id" => 'Lokasi di luar cakupan opname ini.']);
                }
            }
        }

        $service->save($count, $lines, prune: false);
        if ($post) {
            $service->post($count, $request->user()->id);
        }

        return response()->json([
            'message' => $post ? "Opname {$count->number} diposting; selisih sudah masuk ke stok." : 'Hasil hitung tersimpan.',
            'posted' => $post,
        ]);
    }
}
