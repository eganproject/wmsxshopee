<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Antrean offline aplikasi Android mengirim ulang aksi yang jawabannya tidak
 * sampai ke HP (sinyal putus setelah server selesai menyimpan). Tiap aksi
 * membawa header X-Idempotency-Key; jawaban sukses disimpan 7 hari, jadi
 * kiriman ulang dengan kunci yang sama mendapat jawaban lama dan tidak
 * membuat dokumen (penerimaan, retur, penyesuaian) dobel.
 */
class IdempotentRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('X-Idempotency-Key'));
        if ($key === '' || strlen($key) > 100 || ! $request->isMethod('POST') || ! $request->user()) {
            return $next($request);
        }

        $cacheKey = 'idempotency:'.$request->user()->id.':'.sha1($request->path().'|'.$key);
        if ($saved = Cache::get($cacheKey)) {
            return response()->json([...$saved['body'], 'duplicate' => true], $saved['status']);
        }

        // Kiriman yang sama masih diproses (mis. dua kali tekan): minta HP mencoba lagi nanti.
        $lock = Cache::lock($cacheKey.':lock', 120);
        if (! $lock->get()) {
            return response()->json(['message' => 'Aksi ini masih diproses. Coba lagi sebentar.'], 409);
        }

        try {
            $response = $next($request);
            if ($response instanceof JsonResponse && $response->isSuccessful()) {
                Cache::put($cacheKey, ['status' => $response->getStatusCode(), 'body' => (array) $response->getData(true)], now()->addDays(7));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
