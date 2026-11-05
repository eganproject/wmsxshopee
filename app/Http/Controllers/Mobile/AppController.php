<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileAppRelease;
use App\Support\Barcode;
use App\Support\MobileApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Halaman "Aplikasi Android" di Optima web: download APK, QR code, cara pasang & daftar versi. */
class AppController extends Controller
{
    public function page(Request $request): View
    {
        // Link di halaman & QR berlaku 7 hari, cukup untuk dibagikan ke HP tim gudang.
        $app = MobileApp::version($request, 7);

        return view('mobile-app.page', [
            'app' => $app,
            'qr' => Barcode::qr($app['url']),
            'releases' => $request->user()->can('mobile.releases')
                ? MobileAppRelease::with('creator')->orderByDesc('version_code')->get()
                : collect(),
        ]);
    }

    /** Versi APK terbaru, dipakai aplikasi untuk menawarkan (atau mewajibkan) update. */
    public function version(Request $request): JsonResponse
    {
        return response()->json(MobileApp::version($request))->header('Cache-Control', 'no-store');
    }

    /** File APK (link bertanda tangan, tanpa login). Mendukung unduh lanjutan (Range). */
    public function download(MobileAppRelease $release): BinaryFileResponse
    {
        $disk = Storage::disk(MobileAppRelease::DISK);
        abort_unless($disk->exists($release->file_path), 404);

        return response()->download($disk->path($release->file_path), $release->fileName(), [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
}
