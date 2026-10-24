<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Support\DatabaseConflict;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));

        // Shopee mengirim push tanpa token CSRF; keasliannya dicek lewat tanda tangan.
        $middleware->validateCsrfTokens(except: ['shopee/webhook', 'tiktok/webhook']);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Bentrok dengan pengguna lain (deadlock / antre kunci terlalu lama) atau
        // stok yang akan jadi minus: kembali ke form dengan pesan, bukan halaman error.
        $exceptions->render(function (QueryException $e, Request $request) {
            $message = DatabaseConflict::message($e);

            // Hapus master data yang ternyata masih dipakai dokumen lain: tampilkan
            // pesan, bukan halaman error. Data tetap utuh karena hapusnya ditolak database.
            if ($message === null && $request->isMethod('DELETE') && DatabaseConflict::stillReferenced($e)) {
                $message = 'Data ini masih dipakai di dokumen atau data lain sehingga tidak bisa dihapus. Nonaktifkan saja sebagai gantinya.';
            }

            if ($message === null) {
                return null;
            }

            report($e);

            return $request->expectsJson()
                ? response()->json(['message' => $message], 409)
                : back()->withInput()->with('error', $message);
        });
    })->create();
