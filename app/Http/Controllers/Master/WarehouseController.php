<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    public function index(Request $request): View
    {
        $warehouses = Warehouse::query()
            ->withCount(['zones', 'racks', 'locations'])
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
        return view('master.warehouses.create', ['warehouse' => new Warehouse(['is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $warehouse = Warehouse::create($this->validated($request));

        return redirect()->route('master.warehouses.index')
            ->with('success', "Gudang {$warehouse->name} berhasil ditambahkan.");
    }

    public function edit(Warehouse $warehouse): View
    {
        return view('master.warehouses.edit', ['warehouse' => $warehouse]);
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

        $warehouse->delete();

        return redirect()->route('master.warehouses.index')
            ->with('success', "Gudang {$warehouse->name} berhasil dihapus.");
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
        ]);

        return [...$data, 'is_active' => $data['is_active'] ?? false];
    }
}
