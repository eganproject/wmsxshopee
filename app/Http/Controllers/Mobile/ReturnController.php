<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Sales\SalesReturnController;
use App\Models\SalesReturn;
use App\Services\StockService;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Retur dari HP: scan resi retur, tandai tiap barang layak jual / rusak / hilang
 * beserta foto kondisinya, lalu simpan draft atau langsung terima. Aturan sama
 * dengan Optima web (jumlah tidak boleh melebihi yang dikirim, dst.).
 */
class ReturnController extends SalesReturnController
{
    use MobileContext;

    public function mobileIndex(Request $request): View
    {
        $returns = SalesReturn::query()
            ->withSum('items', 'qty_good')
            ->withSum('items', 'qty_damaged')
            ->where('warehouse_id', $this->mobileWarehouseId($request))
            ->latest('id')
            ->limit(30)
            ->get();

        return view('mobile.returns', [
            'returns' => $returns,
            'warehouse' => $this->mobileWarehouse($request),
            'reasons' => SalesReturn::REASONS,
        ]);
    }

    public function mobileStore(Request $request, StockService $stock): JsonResponse
    {
        // Cek hak terima sebelum menyimpan apa pun, supaya tidak tertinggal draft setengah jadi.
        abort_if($request->input('action') === 'receive' && ! $request->user()->can('returns.receive'), 403, 'Akun ini tidak punya hak menerima barang.');
        $request->merge([
            'source' => 'order',
            'return_date' => $request->input('return_date', now()->toDateString()),
        ]);
        $data = $this->validated($request);

        $return = DB::transaction(function () use ($request, $data) {
            $return = SalesReturn::create([
                ...$data['header'],
                'number' => SalesReturn::nextNumber(),
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
            $this->fillItems($request, $return, $data['items']);

            return $return;
        });

        $received = false;
        if ($request->input('action') === 'receive') {
            try {
                Activity::track($return, fn () => $this->post($request, $return, $stock));
                $received = true;
            } catch (ValidationException $e) {
                return response()->json([
                    'message' => "Draft {$return->number} tersimpan, tapi belum bisa diterima: ".collect($e->errors())->flatten()->first(),
                    'number' => $return->number,
                    'received' => false,
                ]);
            }
        }

        return response()->json([
            'message' => $received ? $this->receivedMessage($return) : "Draft retur {$return->number} tersimpan.",
            'number' => $return->number,
            'received' => $received,
        ]);
    }
}
