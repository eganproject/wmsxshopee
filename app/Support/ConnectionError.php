<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * Pesan putus koneksi ke marketplace yang aman ditampilkan di layar.
 *
 * Pesan asli cURL memuat URL lengkap (app_key, sign, access_token, bahkan
 * app_secret saat tukar token), jadi tidak boleh disimpan di last_error /
 * riwayat sinkron. Detail aslinya (dengan rahasia disamarkan) masuk log.
 */
class ConnectionError
{
    /** Parameter URL yang disamarkan di log. */
    private const SECRET_PARAMS = ['app_secret', 'access_token', 'refresh_token', 'auth_code', 'code', 'sign', 'shop_cipher', 'partner_key'];

    public static function message(string $platform, ConnectionException $e): string
    {
        $raw = $e->getMessage();
        Log::warning("Koneksi ke {$platform} gagal", ['error' => self::redact($raw)]);

        $host = preg_match('/(?:connect to|resolve host:?)\s+([a-z0-9.-]+\.[a-z]{2,})/i', $raw, $m) ? $m[1] : null;
        $target = $platform.($host ? " ({$host})" : '');

        $reason = match (true) {
            str_contains($raw, 'cURL error 28:') && str_contains($raw, 'Failed to connect') => "Server {$target} tidak menjawab saat dihubungi (batas waktu koneksi habis).",
            str_contains($raw, 'cURL error 28:') => "Server {$target} terlalu lama membalas (batas waktu habis).",
            str_contains($raw, 'cURL error 6:') => "Alamat server {$target} tidak bisa ditemukan (DNS).",
            str_contains($raw, 'cURL error 7:') => "Koneksi ke {$target} ditolak.",
            str_contains($raw, 'cURL error 35:'), str_contains($raw, 'cURL error 60:') => "Koneksi aman (SSL) ke {$target} gagal.",
            default => "Tidak bisa terhubung ke {$target}.",
        };

        return $reason.' Biasanya gangguan jaringan sementara: Optima mencoba lagi otomatis di sinkron berikutnya. Bila terus muncul, cek koneksi internet server.';
    }

    /**
     * Koneksi gagal dibuka (DNS, ditolak, atau habis waktu saat menyambung): request
     * belum sampai ke server, jadi aman diulang untuk metode apa pun, termasuk POST.
     */
    public static function neverSent(ConnectionException $e): bool
    {
        $raw = $e->getMessage();

        return str_contains($raw, 'cURL error 6:') || str_contains($raw, 'cURL error 7:')
            || (str_contains($raw, 'cURL error 28:') && str_contains($raw, 'Failed to connect'));
    }

    /** Samarkan nilai rahasia di query string sebuah pesan. */
    public static function redact(string $text): string
    {
        $params = implode('|', array_map('preg_quote', self::SECRET_PARAMS));

        return (string) preg_replace('/([?&](?:'.$params.')=)[^&\s)]+/i', '$1***', $text);
    }
}
