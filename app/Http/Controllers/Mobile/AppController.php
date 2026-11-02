<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Support\Barcode;
use App\Support\MobileApp;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/** Halaman "Aplikasi Android" di Optima web: download APK, QR code & cara pasang. */
class AppController extends Controller
{
    public function page(): View
    {
        $app = MobileApp::version();

        return view('mobile-app.page', ['app' => $app, 'qr' => Barcode::qr($app['url'])]);
    }

    /** Versi APK terbaru, dipakai aplikasi untuk menawarkan update. */
    public function version(): JsonResponse
    {
        return response()->json(MobileApp::version());
    }
}
