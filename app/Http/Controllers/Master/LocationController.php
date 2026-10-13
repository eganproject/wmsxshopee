<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseRack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LocationController extends Controller
{
    public function index(Request $request): View
    {
        $locations = WarehouseLocation::query()
            ->with(['warehouse', 'rack.zone'])
            ->when($request->filled('warehouse'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse')))
            ->when($request->filled('rack'), fn ($query) => $query->where('warehouse_rack_id', $request->integer('rack')))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->when($request->filled('search'), fn ($query) => $query->where('code', 'like', '%'.$request->string('search').'%'))
            ->orderBy('warehouse_id')
            ->orderBy('code')
            ->paginate(25)
            ->withQueryString();

        return view('master.locations.index', [
            'locations' => $locations,
            'warehouses' => Warehouse::orderBy('code')->get(),
            'racks' => $this->rackOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('master.locations.create', $this->formData(new WarehouseLocation([
            'warehouse_rack_id' => $request->integer('rack') ?: null,
            'type' => 'bin',
            'is_active' => true,
        ])));
    }

    public function store(Request $request): RedirectResponse
    {
        $location = WarehouseLocation::create($this->validated($request));

        return redirect()->route('master.locations.index', ['rack' => $location->warehouse_rack_id])
            ->with('success', "Lokasi {$location->code} berhasil ditambahkan.");
    }

    public function edit(WarehouseLocation $location): View
    {
        return view('master.locations.edit', $this->formData($location));
    }

    public function update(Request $request, WarehouseLocation $location): RedirectResponse
    {
        $location->update($this->validated($request, $location));

        return redirect()->route('master.locations.index', ['rack' => $location->warehouse_rack_id])
            ->with('success', "Lokasi {$location->code} berhasil diperbarui.");
    }

    public function destroy(WarehouseLocation $location): RedirectResponse
    {
        if ($location->ledgers()->exists()) {
            return back()->with('error', "Lokasi {$location->code} sudah punya riwayat stok sehingga tidak bisa dihapus. Nonaktifkan lokasi ini sebagai gantinya.");
        }

        $location->delete();

        return back()->with('success', "Lokasi {$location->code} berhasil dihapus.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?WarehouseLocation $location = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $rack = WarehouseRack::with('zone')->find($request->integer('warehouse_rack_id'));
        $warehouseId = $rack?->zone->warehouse_id;

        $data = $request->validate([
            'warehouse_rack_id' => ['required', 'integer', Rule::exists('warehouse_racks', 'id')],
            'code' => ['required', 'string', 'alpha_dash', 'max:50',
                Rule::unique('warehouse_locations')->where('warehouse_id', $warehouseId)->ignore($location)],
            'level' => ['nullable', 'integer', 'min:1', 'max:'.($rack->levels ?? 50)],
            'position' => ['nullable', 'integer', 'min:1', 'max:999'],
            'type' => ['required', Rule::in(array_keys(WarehouseLocation::TYPES))],
            'max_qty' => ['nullable', 'integer', 'min:0'],
            'max_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'code.unique' => 'Kode lokasi sudah dipakai di gudang ini.',
        ], [
            'warehouse_rack_id' => 'rak',
            'code' => 'kode lokasi',
            'level' => 'level',
            'position' => 'posisi',
            'type' => 'jenis lokasi',
            'max_qty' => 'kapasitas maksimum',
            'max_weight_kg' => 'berat maksimum',
        ]);

        return [...$data, 'warehouse_id' => $warehouseId, 'is_active' => $data['is_active'] ?? false];
    }

    private function rackOptions()
    {
        return WarehouseRack::with('zone.warehouse')->orderBy('warehouse_zone_id')->orderBy('code')->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(WarehouseLocation $location): array
    {
        return [
            'location' => $location,
            'racks' => $this->rackOptions(),
            'types' => WarehouseLocation::TYPES,
        ];
    }
}
