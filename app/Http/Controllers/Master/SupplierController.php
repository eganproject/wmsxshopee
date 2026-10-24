<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\DamagedDocument;
use App\Models\Supplier;
use App\Support\XlsxExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupplierController extends Controller
{
    public function index(Request $request): View|BinaryFileResponse
    {
        $query = Supplier::query()
            ->withCount('receipts')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%"));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->orderBy('name');

        if ($request->input('export') === 'xlsx') {
            return XlsxExport::download('supplier-'.now()->format('Ymd-His').'.xlsx',
                ['Kode', 'Nama', 'Kontak', 'Telepon', 'Email', 'Alamat', 'Kota', 'NPWP', 'Termin', 'Jumlah Penerimaan', 'Status', 'Catatan'],
                $query->lazy(500)->map(fn ($s) => [
                    $s->code, $s->name, $s->contact_name, $s->phone, $s->email, $s->address, $s->city, $s->npwp, $s->paymentTermLabel(),
                    $s->receipts_count, $s->is_active ? 'Aktif' : 'Nonaktif', $s->notes,
                ]));
        }

        $suppliers = $query->paginate(15)->withQueryString();

        return view('master.suppliers.index', ['suppliers' => $suppliers]);
    }

    public function create(): View
    {
        return view('master.suppliers.create', ['supplier' => new Supplier(['is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $supplier = Supplier::create($this->validated($request));

        return redirect()->route('master.suppliers.index')
            ->with('success', "Supplier {$supplier->name} berhasil ditambahkan.");
    }

    public function edit(Supplier $supplier): View
    {
        return view('master.suppliers.edit', ['supplier' => $supplier]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request, $supplier));

        return redirect()->route('master.suppliers.index')
            ->with('success', "Supplier {$supplier->name} berhasil diperbarui.");
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        if ($supplier->receipts()->exists() || DamagedDocument::withoutGlobalScope('warehouse_access')->where('supplier_id', $supplier->id)->exists()) {
            return back()->with('error', "Supplier {$supplier->name} sudah punya penerimaan barang atau retur barang rusak sehingga tidak bisa dihapus. Nonaktifkan supplier ini sebagai gantinya.");
        }

        $supplier->delete();

        return redirect()->route('master.suppliers.index')
            ->with('success', "Supplier {$supplier->name} berhasil dihapus.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'alpha_dash', 'max:20', Rule::unique('suppliers')->ignore($supplier)],
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'npwp' => ['nullable', 'string', 'max:30'],
            'payment_term_days' => ['nullable', 'integer', 'between:0,365'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'code' => 'kode supplier',
            'name' => 'nama supplier',
            'contact_name' => 'nama kontak',
            'phone' => 'no. telepon',
            'address' => 'alamat',
            'city' => 'kota',
            'npwp' => 'NPWP',
            'payment_term_days' => 'termin bayar',
            'notes' => 'catatan',
        ]);

        return [...$data, 'is_active' => $data['is_active'] ?? false];
    }
}
