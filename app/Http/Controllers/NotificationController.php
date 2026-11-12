<?php

namespace App\Http\Controllers;

use App\Services\AlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Halaman Notifikasi: semua peringatan yang boleh dilihat user, dengan contoh baris per grup. */
class NotificationController extends Controller
{
    public function index(Request $request, AlertService $alerts): View
    {
        $user = $request->user();

        // Halaman ini selalu menghitung ulang, jadi lonceng ikut disegarkan.
        AlertService::forget($user);

        return view('notifications.index', [
            'groups' => $alerts->groups($user, 10),
        ]);
    }

    /**
     * Tandai dibaca: satu baris (group + item), satu grup (group), atau semuanya.
     * Peringatannya tetap tampil selama masalahnya ada, hanya tidak dihitung baru lagi.
     */
    public function read(Request $request, AlertService $alerts): RedirectResponse
    {
        $data = $request->validate([
            'group' => ['nullable', 'string', 'max:40', 'regex:/^[a-z_]+$/'],
            'item' => ['nullable', 'string', 'max:191'],
        ]);

        $user = $request->user();
        $group = $data['group'] ?? null;

        if ($group !== null && ! empty($data['item'])) {
            $alerts->markItemRead($user, $group, $data['item']);
        } else {
            $alerts->markRead($user, $group);
        }

        return back()->with('success', $group === null ? 'Semua notifikasi ditandai sudah dibaca.' : 'Notifikasi ditandai sudah dibaca.');
    }
}
