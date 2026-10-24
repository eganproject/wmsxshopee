<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\SalesOrder;
use App\Models\StockLedger;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    public function index(Request $request): View
    {
        $warehouses = Warehouse::query()
            ->withCount(['zones', 'racks', 'locations'])
            ->with(['supplyWarehouse' => fn ($q) => $q->withoutGlobalScope('warehouse_access')])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%"));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->orderBy('code')
            ->paginate(10)
            ->withQueryString();

        return view('master.warehouses.index', ['warehouses' => $warehouses]);
    }

    public function create(): View
    {
        return view('master.warehouses.create', ['warehouse' => new Warehouse(['is_active' => true, 'supply_stock_percent' => 100]), 'supplyOptions' => $this->supplyOptions()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $warehouse = DB::transaction(function () use ($request) {
            $warehouse = Warehouse::create($this->validated($request));

            // User dengan akses gudang terbatas otomatis mendapat akses ke gudang
            // yang baru dibuatnya, supaya gudang itu tidak langsung hilang dari daftar.
            $user = $request->user();
            if (! $user->hasAllWarehouses()) {
                $user->syncWarehouseAccess(false, [...$user->accessibleWarehouseIds(), $warehouse->id]);
            }

            return $warehouse;
        });

        return redirect()->route('master.warehouses.index')
            ->with('success', "Gudang {$warehouse->name} berhasil ditambahkan.");
    }

    public function edit(Warehouse $warehouse): View
    {
        return view('master.warehouses.edit', ['warehouse' => $warehouse, 'supplyOptions' => $this->supplyOptions($warehouse)]);
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $warehouse->update($this->validated($request, $warehouse));

        return redirect()->route('master.warehouses.index')
            ->with('success', "Gudang {$warehouse->name} berhasil diperbarui.");
    }

    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        if ($warehouse->zones()->exists()) {
            return back()->with('error', "Gudang {$warehouse->name} masih memiliki zona. Hapus zona terlebih dahulu atau nonaktifkan gudang.");
        }

        // Stok tanpa bin dan dokumen tidak butuh zona, jadi dicek terpisah.
        $used = StockLedger::withoutGlobalScope('warehouse_access')->where('warehouse_id', $warehouse->id)->exists()
            || SalesOrder::withoutGlobalScope('warehouse_access')->where('warehouse_id', $warehouse->id)->exists()
            || GoodsReceipt::withoutGlobalScope('warehouse_access')->where('warehouse_id', $warehouse->id)->exists();

        if ($used) {
            return back()->with('error', "Gudang {$warehouse->name} sudah punya riwayat stok atau dokumen sehingga tidak bisa dihapus. Nonaktifkan gudang ini sebagai gantinya.");
        }

        $warehouse->delete();

        return redirect()->route('master.warehouses.index')
            ->with('success', "Gudang {$warehouse->name} berhasil dihapus.");
    }

    /** Pilihan gudang cadangan: semua gudang lain (tanpa batasan akses user). */
    private function supplyOptions(?Warehouse $warehouse = null)
    {
        return Warehouse::withoutGlobalScope('warehouse_access')->when($warehouse, fn ($q) => $q->whereKeyNot($warehouse->id))->orderBy('code')->get(['id', 'code', 'name', 'is_active']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Warehouse $warehouse = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'alpha_dash', 'max:20', Rule::unique('warehouses')->ignore($warehouse)],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:30'],
            'pic_name' => ['nullable', 'string', 'max:100'],
            'supply_warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id'), Rule::notIn(array_filter([$warehouse?->id]))],
            'supply_stock_percent' => ['nullable', 'integer', 'between:0,100'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'code' => 'kode gudang',
            'name' => 'nama gudang',
            'address' => 'alamat',
            'city' => 'kota',
            'province' => 'provinsi',
            'postal_code' => 'kode pos',
            'phone' => 'no. telepon',
            'pic_name' => 'penanggung jawab',
            'notes' => 'catatan',
            'supply_warehouse_id' => 'gudang cadangan',
            'supply_stock_percent' => 'persentase stok cadangan',
        ]);

        // Satu tingkat saja: gudang cadangan tidak boleh memakai gudang ini sebagai cadangannya.
        if (filled($data['supply_warehouse_id'] ?? null) && $warehouse
            && Warehouse::withoutGlobalScope('warehouse_access')->whereKey($data['supply_warehouse_id'])->value('supply_warehouse_id') === $warehouse->id) {
            throw ValidationException::withMessages(['supply_warehouse_id' => 'Gudang ini sudah menjadi cadangan gudang tersebut; tidak bisa saling jadi cadangan.']);
        }

        return [
            ...$data,
            'supply_warehouse_id' => $data['supply_warehouse_id'] ?? null,
            'supply_stock_percent' => $data['supply_stock_percent'] ?? 100,
            'is_active' => $data['is_active'] ?? false,
        ];
    }
}
