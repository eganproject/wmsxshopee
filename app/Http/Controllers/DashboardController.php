<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('dashboard', [
            'stats' => $user->can('dashboard.view') ? [
                'users' => User::count(),
                'active_users' => User::active()->count(),
                'roles' => Role::count(),
                'permissions' => Permission::count(),
            ] : null,
            'recentLogins' => $user->can('users.view')
                ? User::whereNotNull('last_login_at')->latest('last_login_at')->limit(5)->get()
                : collect(),
        ]);
    }
}
