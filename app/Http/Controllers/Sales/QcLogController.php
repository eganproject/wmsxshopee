<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\QcExemptVariant;
use App\Models\QcSubstitution;
use App\Models\SalesOrder;
use App\Models\ScanDuplicateAttempt;
use App\Models\Warehouse;
use App\Support\XlsxExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Riwayat & pengaturan QC kemas: substitusi SKU, percobaan scan resi ganda,
 * dan daftar SKU yang dikecualikan dari scan QC.
 */
class QcLogController extends Controller
{
    public function substitutions(Request $request): View|BinaryFileResponse
    {
        [$from, $to] = $this->range($request);
        $search = trim((string) $request->input('search'));

        $query = QcSubstitution::query()
            ->with(['order.warehouse', 'fromVariant.product', 'toVariant.product', 'user'])
            // Pesanan di luar akses gudang user tidak ikut tampil.
            ->whereHas('order', fn (Builder $q) => $q->when($request->filled('warehouse'), fn ($q) => $q->where('warehouse_id', $request->integer('warehouse'))))
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('reason', 'like', "%{$search}%")
                ->orWhereHas('order', fn ($o) => $o->where('number', 'like', "%{$search}%")->orWhere('tracking_number', 'like', "%{$search}%"))
                ->orWhereHas('fromVariant', fn ($v) => $v->where('sku', 'like', "%{$search}%"))
                ->orWhereHas('toVariant', fn ($v) => $v->where('sku', 'like', "%{$search}%"))))
            ->latest('id');

        if ($request->input('export') === 'xlsx') {
            return XlsxExport::download('substitusi-qc-'.now()->format('Ymd-His').'.xlsx',
                ['Waktu', 'Pesanan', 'Resi', 'Gudang', 'SKU Asal', 'SKU Pengganti', 'Qty', 'Alasan', 'Oleh'],
                $query->lazy(500)->map(fn (QcSubstitution $row) => [
                    $row->created_at->format('d/m/Y H:i'), $row->order?->number, $row->order?->tracking_number, $row->order?->warehouse?->code,
                    $row->fromVariant?->sku, $row->toVariant?->sku, $row->qty, $row->reason, $row->user?->name,
                ]));
        }

        return view('sales.qc.substitutions', [
            'rows' => $query->paginate(50)->withQueryString(),
            'from' => $from,
            'to' => $to,
            'warehouses' => Warehouse::orderBy('code')->get(),
        ]);
    }

    public function duplicates(Request $request): View|BinaryFileResponse
    {
        [$from, $to] = $this->range($request);
        $search = trim((string) $request->input('search'));

        $query = ScanDuplicateAttempt::query()
            ->with(['order', 'warehouse', 'user'])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($request->filled('warehouse'), fn ($q) => $q->where('warehouse_id', $request->integer('warehouse')))
            ->when(array_key_exists((string) $request->input('context'), ScanDuplicateAttempt::CONTEXTS), fn ($q) => $q->where('context', $request->input('context')))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhereHas('order', fn ($o) => $o->where('number', 'like', "%{$search}%"))))
            ->latest('id');

        if ($request->input('export') === 'xlsx') {
            return XlsxExport::download('scan-resi-ganda-'.now()->format('Ymd-His').'.xlsx',
                ['Waktu', 'Kode Di-scan', 'Tempat Scan', 'Pesanan', 'Status Saat Di-scan', 'Gudang', 'Oleh'],
                $query->lazy(500)->map(fn (ScanDuplicateAttempt $row) => [
                    $row->created_at->format('d/m/Y H:i'), $row->code, $row->contextLabel(), $row->order?->number,
                    SalesOrder::STATUSES[$row->order_status]['label'] ?? $row->order_status, $row->warehouse?->code, $row->user?->name,
                ]));
        }

        return view('sales.qc.duplicates', [
            'rows' => $query->paginate(50)->withQueryString(),
            'from' => $from,
            'to' => $to,
            'warehouses' => Warehouse::orderBy('code')->get(),
        ]);
    }

    public function exempt(Request $request): View
    {
        $search = trim((string) $request->input('search'));

        return view('sales.qc.exempt', [
            'rows' => QcExemptVariant::query()
                ->with(['variant.product', 'creator'])
                ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                    ->where('reason', 'like', "%{$search}%")
                    ->orWhereHas('variant', fn ($v) => $v->where('sku', 'like', "%{$search}%")
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")))))
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    public function storeExempt(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id'), Rule::unique('qc_exempt_variants', 'product_variant_id')],
            'reason' => ['nullable', 'string', 'max:255'],
        ], ['product_variant_id.unique' => 'SKU ini sudah ada di daftar tanpa scan QC.'], ['product_variant_id' => 'varian', 'reason' => 'alasan']);

        QcExemptVariant::create([...$data, 'created_by' => $request->user()->id]);

        return back()->with('success', ProductVariant::whereKey($data['product_variant_id'])->value('sku').' tidak perlu di-scan lagi saat QC kemas.');
    }

    public function destroyExempt(QcExemptVariant $exempt): RedirectResponse
    {
        $exempt->load('variant');
        $exempt->delete();

        return back()->with('success', "{$exempt->variant?->sku} kembali wajib di-scan saat QC kemas.");
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        $from = $request->date('from') ?? now()->subDays(29);
        $to = $request->date('to') ?? now();

        return $to->lt($from) ? [$to, $from] : [$from, $to];
    }
}
