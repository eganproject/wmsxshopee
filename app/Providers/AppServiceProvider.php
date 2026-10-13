<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Carbon;
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

        Carbon::setLocale(config('app.locale'));

        Password::defaults(fn () => Password::min(8)->letters()->numbers());
    }
}
