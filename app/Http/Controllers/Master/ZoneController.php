<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Models\WarehouseZone;
use App\Rules\AccessibleRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ZoneController extends Controller
{
    public function index(Request $request): View
    {
        $zones = WarehouseZone::query()
            ->with('warehouse')
            ->withCount('racks')
            ->when($request->filled('warehouse'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->input('type')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
            })
            ->orderBy('warehouse_id')
            ->orderBy('code')
            ->paginate(15)
            ->withQueryString();

        return view('master.zones.index', [
            'zones' => $zones,
            'warehouses' => Warehouse::orderBy('code')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('master.zones.create', $this->formData(new WarehouseZone([
            'warehouse_id' => $request->integer('warehouse') ?: null,
            'type' => 'storage',
            'is_active' => true,
        ])));
    }

    public function store(Request $request): RedirectResponse
    {
        $zone = WarehouseZone::create($this->validated($request));

        return redirect()->route('master.zones.index', ['warehouse' => $zone->warehouse_id])
            ->with('success', "Zona {$zone->name} berhasil ditambahkan.");
    }

    public function edit(WarehouseZone $zone): View
    {
        return view('master.zones.edit', $this->formData($zone));
    }

    public function update(Request $request, WarehouseZone $zone): RedirectResponse
    {
        $data = $this->validated($request, $zone);

        if ($data['warehouse_id'] !== $zone->warehouse_id && $zone->racks()->exists()) {
            return back()->withInput()->with('error', 'Gudang tidak bisa dipindah karena zona ini sudah memiliki rak.');
        }

        $zone->update($data);

        return redirect()->route('master.zones.index', ['warehouse' => $zone->warehouse_id])
            ->with('success', "Zona {$zone->name} berhasil diperbarui.");
    }

    public function destroy(WarehouseZone $zone): RedirectResponse
    {
        if ($zone->racks()->exists()) {
            return back()->with('error', "Zona {$zone->name} masih memiliki rak. Hapus rak terlebih dahulu atau nonaktifkan zona.");
        }

        $zone->delete();

        return back()->with('success', "Zona {$zone->name} berhasil dihapus.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?WarehouseZone $zone = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id'), new AccessibleRecord(Warehouse::class)],
            'code' => ['required', 'string', 'alpha_dash', 'max:20',
                Rule::unique('warehouse_zones')->where('warehouse_id', $request->integer('warehouse_id'))->ignore($zone)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(WarehouseZone::TYPES))],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'code.unique' => 'Kode zona sudah dipakai di gudang ini.',
        ], [
            'warehouse_id' => 'gudang',
            'code' => 'kode zona',
            'name' => 'nama zona',
            'type' => 'jenis zona',
            'description' => 'keterangan',
        ]);

        return [...$data, 'warehouse_id' => (int) $data['warehouse_id'], 'is_active' => $data['is_active'] ?? false];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(WarehouseZone $zone): array
    {
        return [
            'zone' => $zone,
            'warehouses' => Warehouse::orderBy('code')->get(),
            'types' => WarehouseZone::TYPES,
        ];
    }
}
