<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseRack;
use App\Models\WarehouseZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RackController extends Controller
{
    public function index(Request $request): View
    {
        $racks = WarehouseRack::query()
            ->with('zone.warehouse')
            ->withCount('locations')
            ->when($request->filled('warehouse'), fn ($query) => $query->whereHas('zone', fn ($q) => $q->where('warehouse_id', $request->integer('warehouse'))))
            ->when($request->filled('zone'), fn ($query) => $query->where('warehouse_zone_id', $request->integer('zone')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
            })
            ->orderBy('warehouse_zone_id')
            ->orderBy('code')
            ->paginate(15)
            ->withQueryString();

        return view('master.racks.index', [
            'racks' => $racks,
            'warehouses' => Warehouse::orderBy('code')->get(),
            'zones' => $this->zoneOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('master.racks.create', $this->formData(new WarehouseRack([
            'warehouse_zone_id' => $request->integer('zone') ?: null,
            'levels' => 4,
            'is_active' => true,
        ])));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        [$rack, $created] = DB::transaction(function () use ($data) {
            $rack = WarehouseRack::create($data['rack']);

            return [$rack, $data['positions'] ? $this->generateLocations($rack, $data['positions']) : 0];
        });

        return redirect()->route('master.racks.index', ['zone' => $rack->warehouse_zone_id])
            ->with('success', "Rak {$rack->code} berhasil ditambahkan".($created ? " beserta {$created} lokasi bin." : '.'));
    }

    public function edit(WarehouseRack $rack): View
    {
        return view('master.racks.edit', $this->formData($rack->loadCount('locations')));
    }

    public function update(Request $request, WarehouseRack $rack): RedirectResponse
    {
        $data = $this->validated($request, $rack);
        $newZone = WarehouseZone::find($data['rack']['warehouse_zone_id']);

        if ($newZone->warehouse_id !== $rack->zone->warehouse_id && $rack->locations()->exists()) {
            return back()->withInput()->with('error', 'Rak yang sudah memiliki lokasi bin tidak bisa dipindah ke gudang lain.');
        }

        $created = DB::transaction(function () use ($data, $rack) {
            $rack->update($data['rack']);
            $rack->load('zone');

            return $data['positions'] ? $this->generateLocations($rack, $data['positions']) : 0;
        });

        return redirect()->route('master.racks.index', ['zone' => $rack->warehouse_zone_id])
            ->with('success', "Rak {$rack->code} berhasil diperbarui".($created ? " dan {$created} lokasi bin baru dibuat." : '.'));
    }

    public function destroy(WarehouseRack $rack): RedirectResponse
    {
        if ($rack->locations()->exists()) {
            return back()->with('error', "Rak {$rack->code} masih memiliki lokasi bin. Hapus lokasi terlebih dahulu atau nonaktifkan rak.");
        }

        $rack->delete();

        return back()->with('success', "Rak {$rack->code} berhasil dihapus.");
    }

    /**
     * Membuat lokasi bin untuk setiap level × posisi yang belum ada.
     */
    private function generateLocations(WarehouseRack $rack, int $positions): int
    {
        $warehouseId = $rack->zone->warehouse_id;
        $existing = WarehouseLocation::where('warehouse_id', $warehouseId)->pluck('code')->flip();
        $created = 0;

        for ($level = 1; $level <= $rack->levels; $level++) {
            for ($position = 1; $position <= $positions; $position++) {
                $code = WarehouseLocation::makeCode($rack->code, $level, $position);

                if ($existing->has($code)) {
                    continue;
                }

                $rack->locations()->create([
                    'warehouse_id' => $warehouseId,
                    'code' => $code,
                    'level' => $level,
                    'position' => $position,
                    'type' => 'bin',
                    'is_active' => true,
                ]);
                $created++;
            }
        }

        return $created;
    }

    /**
     * @return array{rack: array<string, mixed>, positions: int}
     */
    private function validated(Request $request, ?WarehouseRack $rack = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        // Kode rak unik per gudang, karena menjadi awalan kode lokasi bin.
        $zone = WarehouseZone::find($request->integer('warehouse_zone_id'));
        $sameWarehouseZones = $zone ? WarehouseZone::where('warehouse_id', $zone->warehouse_id)->pluck('id') : collect();

        $data = $request->validate([
            'warehouse_zone_id' => ['required', 'integer', Rule::exists('warehouse_zones', 'id')],
            'code' => ['required', 'string', 'alpha_dash', 'max:20',
                Rule::unique('warehouse_racks')->whereIn('warehouse_zone_id', $sameWarehouseZones->all())->ignore($rack)],
            'name' => ['nullable', 'string', 'max:255'],
            'levels' => ['required', 'integer', 'min:1', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            'generate_locations' => ['nullable', 'boolean'],
            'positions' => ['nullable', 'required_if_accepted:generate_locations', 'integer', 'min:1', 'max:100'],
        ], [
            'code.unique' => 'Kode rak sudah dipakai di gudang ini.',
        ], [
            'warehouse_zone_id' => 'zona',
            'code' => 'kode rak',
            'name' => 'nama rak',
            'levels' => 'jumlah level',
            'description' => 'keterangan',
            'positions' => 'jumlah bin per level',
        ]);

        return [
            'rack' => [
                ...collect($data)->only(['warehouse_zone_id', 'code', 'name', 'levels', 'description'])->all(),
                'is_active' => $data['is_active'] ?? false,
            ],
            'positions' => ! empty($data['generate_locations']) ? (int) $data['positions'] : 0,
        ];
    }

    private function zoneOptions()
    {
        return WarehouseZone::with('warehouse')->orderBy('warehouse_id')->orderBy('code')->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(WarehouseRack $rack): array
    {
        return [
            'rack' => $rack,
            'zones' => $this->zoneOptions(),
        ];
    }
}
