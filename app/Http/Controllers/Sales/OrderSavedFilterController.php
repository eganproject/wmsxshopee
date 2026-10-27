<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\SavedFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Pencarian tersimpan di daftar Pesanan, milik masing-masing user. */
class OrderSavedFilterController extends Controller
{
    public const PAGE = 'sales.orders';

    /** Parameter daftar pesanan yang ikut disimpan. */
    public const KEYS = ['status', 'mp', 'platform', 'channel', 'courier', 'search', 'search_by', 'warehouse', 'shipment', 'label', 'date_by', 'from', 'to', 'sort', 'per_page'];

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'query' => ['nullable', 'string', 'max:20000'],
        ]);

        parse_str((string) ($data['query'] ?? ''), $query);
        $query = collect($query)->only(self::KEYS)->filter(fn ($value) => is_string($value) && $value !== '')->all();

        if ($query === []) {
            throw ValidationException::withMessages(['name' => 'Pilih dulu filter yang mau disimpan.']);
        }

        $filters = SavedFilter::where('user_id', $request->user()->id)->where('page', self::PAGE);
        $existing = (clone $filters)->where('name', $data['name'])->first();

        if (! $existing && $filters->count() >= SavedFilter::MAX_PER_PAGE) {
            throw ValidationException::withMessages(['name' => 'Maksimal '.SavedFilter::MAX_PER_PAGE.' pencarian tersimpan. Hapus salah satu dulu.']);
        }

        // Nama yang sama menimpa pencarian lama.
        SavedFilter::updateOrCreate(
            ['user_id' => $request->user()->id, 'page' => self::PAGE, 'name' => $data['name']],
            ['query' => $query],
        );

        return redirect()->route('sales.orders.index', $query)->with('success', "Pencarian \"{$data['name']}\" disimpan.");
    }

    public function destroy(Request $request, SavedFilter $savedFilter): RedirectResponse
    {
        abort_unless($savedFilter->user_id === $request->user()->id && $savedFilter->page === self::PAGE, 404);

        $savedFilter->delete();

        return back()->with('success', "Pencarian \"{$savedFilter->name}\" dihapus.");
    }
}
