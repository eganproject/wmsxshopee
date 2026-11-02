<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Pengaturan printer Bluetooth & cetak label produk / rak dari HP. */
class PrintController extends Controller
{
    use MobileContext;

    public function __invoke(Request $request): View
    {
        return view('mobile.print', ['warehouse' => $this->mobileWarehouse($request)]);
    }
}
