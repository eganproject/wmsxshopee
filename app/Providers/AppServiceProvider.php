<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Activity;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Super Admin melewati semua pengecekan permission.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        // Menu Marketplace terbuka bila user punya hak di salah satu platform.
        Gate::define('marketplace.view', fn (User $user) => $user->canAny(['shopee.view', 'tiktok.view']));
        Gate::define('marketplace.settings', fn (User $user) => $user->canAny(['shopee.settings', 'tiktok.settings']));

        Carbon::setLocale(config('app.locale'));

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // Log aktivitas untuk login, logout & percobaan login gagal.
        Event::listen(Login::class, fn (Login $event) => $event->user instanceof User
            && Activity::record('login', 'auth', "{$event->user->name} login", $event->user, user: $event->user));
        Event::listen(Logout::class, fn (Logout $event) => $event->user instanceof User
            && Activity::record('logout', 'auth', "{$event->user->name} logout", $event->user, user: $event->user));
        Event::listen(Failed::class, function (Failed $event) {
            $login = Arr::first(Arr::except($event->credentials, ['password', 'is_active']));
            $user = $event->user instanceof User ? $event->user : null;

            Activity::record('login_failed', 'auth', "Login gagal untuk \"{$login}\"", $user,
                new: ['Login' => (string) $login, 'Alasan' => $user ? 'Password salah' : 'Akun tidak ditemukan atau nonaktif']);
        });
    }
}
