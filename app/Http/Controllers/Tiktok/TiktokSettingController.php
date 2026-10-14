<?php

namespace App\Http\Controllers\Tiktok;

use App\Http\Controllers\Controller;
use App\Models\TiktokSetting;
use App\Models\TiktokShop;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TiktokSettingController extends Controller
{
    public function edit(): View
    {
        return view('tiktok.settings', [
            'setting' => TiktokSetting::current(),
            'hosts' => config('tiktok.hosts'),
            'shopCount' => TiktokShop::count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_key' => ['required', 'string', 'max:100'],
            'app_secret' => ['nullable', 'string', 'max:255'],
            'service_id' => ['nullable', 'string', 'max:100'],
            'redirect_base_url' => ['nullable', 'url:http,https', 'max:255'],
        ], [], [
            'app_key' => 'App Key',
            'app_secret' => 'App Secret',
            'service_id' => 'Service ID',
            'redirect_base_url' => 'domain redirect',
        ]);

        $setting = TiktokSetting::current();

        // App secret tidak pernah ditampilkan lagi: kosong berarti tetap pakai yang tersimpan.
        if (blank($data['app_secret'] ?? null)) {
            unset($data['app_secret']);
        }

        $setting->fill($data);

        if (! $setting->isConfigured()) {
            return back()->withInput()->withErrors(['app_secret' => 'Isi App Secret dari Partner Center.']);
        }

        $changed = array_keys($setting->getDirty());
        $setting->save();

        if ($changed) {
            // Hanya nama field yang dicatat, bukan nilainya (app secret rahasia).
            Activity::record('updated', 'tiktok', 'Pengaturan TikTok diubah', $setting, [], ['field' => implode(', ', $changed)]);
        }

        return redirect()->route('tiktok.settings.edit')->with('success', 'Pengaturan TikTok disimpan.');
    }
}
