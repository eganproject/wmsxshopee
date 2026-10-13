<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Support\XlsxExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CourierController extends Controller
{
    public function index(Request $request): View|BinaryFileResponse
    {
        $query = Courier::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->orderBy('name');

        if ($request->input('export') === 'xlsx') {
            return XlsxExport::download('kurir-'.now()->format('Ymd-His').'.xlsx',
                ['Kode', 'Nama', 'Layanan', 'Kontak', 'Telepon', 'Batas Pickup', 'URL Lacak', 'Status', 'Catatan'],
                $query->lazy(500)->map(fn ($c) => [
                    $c->code, $c->name, collect($c->services ?? [])->map(fn ($s) => trim(($s['code'] ?? '').' '.($s['name'] ?? '')))->implode(', '), $c->contact_name, $c->contact_phone,
                    $c->pickup_cutoff ? substr((string) $c->pickup_cutoff, 0, 5) : null, $c->tracking_url, $c->is_active ? 'Aktif' : 'Nonaktif', $c->notes,
                ]));
        }

        $couriers = $query->paginate(15)->withQueryString();

        return view('master.couriers.index', ['couriers' => $couriers]);
    }

    public function create(): View
    {
        return view('master.couriers.create', ['courier' => new Courier(['is_active' => true, 'services' => []])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $courier = Courier::create($this->validated($request));

        return redirect()->route('master.couriers.index')
            ->with('success', "Kurir {$courier->name} berhasil ditambahkan.");
    }

    public function edit(Courier $courier): View
    {
        return view('master.couriers.edit', ['courier' => $courier]);
    }

    public function update(Request $request, Courier $courier): RedirectResponse
    {
        $courier->update($this->validated($request, $courier));

        return redirect()->route('master.couriers.index')
            ->with('success', "Kurir {$courier->name} berhasil diperbarui.");
    }

    public function destroy(Courier $courier): RedirectResponse
    {
        $courier->delete();

        return redirect()->route('master.couriers.index')
            ->with('success', "Kurir {$courier->name} berhasil dihapus.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Courier $courier = null): array
    {
        $request->merge([
            'code' => strtoupper(trim((string) $request->input('code'))),
            // Buang baris layanan yang kosong dari form.
            'services' => collect($request->input('services', []))
                ->map(fn ($service) => [
                    'code' => strtoupper(trim((string) ($service['code'] ?? ''))),
                    'name' => trim((string) ($service['name'] ?? '')),
                ])
                ->reject(fn ($service) => $service['code'] === '' && $service['name'] === '')
                ->values()
                ->all(),
        ]);

        $data = $request->validate([
            'code' => ['required', 'string', 'alpha_dash', 'max:20', Rule::unique('couriers')->ignore($courier)],
            'name' => ['required', 'string', 'max:255'],
            'services' => ['array', 'max:30'],
            'services.*.code' => ['required', 'string', 'alpha_dash', 'max:20', 'distinct'],
            'services.*.name' => ['required', 'string', 'max:100'],
            'tracking_url' => ['nullable', 'string', 'starts_with:http://,https://', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'pickup_cutoff' => ['nullable', 'date_format:H:i'],
            'shopee_logistics_channel_id' => ['nullable', 'string', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'services.*.code.distinct' => 'Kode layanan tidak boleh duplikat.',
        ], [
            'code' => 'kode kurir',
            'name' => 'nama kurir',
            'services.*.code' => 'kode layanan',
            'services.*.name' => 'nama layanan',
            'tracking_url' => 'URL lacak',
            'contact_name' => 'nama kontak',
            'contact_phone' => 'no. telepon kontak',
            'pickup_cutoff' => 'batas jam pickup',
            'shopee_logistics_channel_id' => 'ID channel logistik Shopee',
            'notes' => 'catatan',
        ]);

        return [...$data, 'services' => $data['services'] ?? [], 'is_active' => $data['is_active'] ?? false];
    }
}
