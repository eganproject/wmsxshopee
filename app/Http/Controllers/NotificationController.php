<?php

namespace App\Http\Controllers;

use App\Services\AlertService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Halaman Notifikasi: semua peringatan yang boleh dilihat user, dengan contoh baris per grup. */
class NotificationController extends Controller
{
    public function __invoke(Request $request, AlertService $alerts): View
    {
        $user = $request->user();

        // Halaman ini selalu menghitung ulang, jadi lonceng ikut disegarkan.
        AlertService::forget($user);

        return view('notifications.index', [
            'groups' => $alerts->groups($user, 10),
        ]);
    }
}
