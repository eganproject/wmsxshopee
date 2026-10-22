<?php

namespace App\Http\Controllers;

use App\Support\SettingsMenu;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('settings.index', [
            'groups' => SettingsMenu::visibleGroups($request->user()),
        ]);
    }
}
