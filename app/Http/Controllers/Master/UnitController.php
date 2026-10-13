<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UnitController extends Controller
{
    public function index(Request $request): View
    {
        $units = Unit::query()
            ->withCount('products')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->orderBy('code')
            ->paginate(15)
            ->withQueryString();

        return view('master.units.index', ['units' => $units]);
    }

    public function create(): View
    {
        return view('master.units.create', ['unit' => new Unit(['is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $unit = Unit::create($this->validated($request));

        return redirect()->route('master.units.index')
            ->with('success', "Satuan {$unit->name} berhasil ditambahkan.");
    }

    public function edit(Unit $unit): View
    {
        return view('master.units.edit', ['unit' => $unit]);
    }

    public function update(Request $request, Unit $unit): RedirectResponse
    {
        $unit->update($this->validated($request, $unit));

        return redirect()->route('master.units.index')
            ->with('success', "Satuan {$unit->name} berhasil diperbarui.");
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        if ($unit->products()->exists()) {
            return back()->with('error', "Satuan {$unit->name} masih dipakai produk. Ganti satuan produk tersebut atau nonaktifkan satuan.");
        }

        $unit->delete();

        return redirect()->route('master.units.index')
            ->with('success', "Satuan {$unit->name} berhasil dihapus.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Unit $unit = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'alpha_dash', 'max:20', Rule::unique('units')->ignore($unit)],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'code' => 'kode satuan',
            'name' => 'nama satuan',
        ]);

        return [...$data, 'is_active' => $data['is_active'] ?? false];
    }
}
