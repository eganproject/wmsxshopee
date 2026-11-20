<?php

namespace App\Http\Controllers;

use App\Support\ReportsMenu;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Halaman Laporan: kartu semua laporan dalam satu tempat (menggantikan 8 menu sidebar). */
class ReportsIndexController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('reports.index', [
            'groups' => ReportsMenu::visibleGroups($request->user()),
        ]);
    }
}
