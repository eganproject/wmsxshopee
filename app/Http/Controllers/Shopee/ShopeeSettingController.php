<?php

namespace App\Http\Controllers\Shopee;

use App\Http\Controllers\Controller;
use App\Models\ShopeeSetting;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShopeeSettingController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'environment' => ['required', Rule::in(array_keys(ShopeeSetting::ENVIRONMENTS))],
            'test_partner_id' => ['nullable', 'integer', 'min:1'],
            'test_partner_key' => ['nullable', 'string', 'max:255'],
            'live_partner_id' => ['nullable', 'integer', 'min:1'],
            'live_partner_key' => ['nullable', 'string', 'max:255'],
            'test_push_key' => ['nullable', 'string', 'max:255'],
            'live_push_key' => ['nullable', 'string', 'max:255'],
            'redirect_base_url' => ['nullable', 'url:http,https', 'max:255'],
        ], [], [
            'environment' => 'mode',
            'test_partner_id' => 'Partner ID test',
            'test_partner_key' => 'Partner Key test',
            'live_partner_id' => 'Partner ID live',
            'live_partner_key' => 'Partner Key live',
            'test_push_key' => 'Live Push Partner Key test',
            'live_push_key' => 'Live Push Partner Key live',
            'redirect_base_url' => 'domain redirect',
        ]);

        $setting = ShopeeSetting::current();

        // Partner key & push key tidak pernah ditampilkan lagi: kosong berarti tetap pakai yang tersimpan.
        foreach (['test_partner_key', 'live_partner_key', 'test_push_key', 'live_push_key'] as $key) {
            if (blank($data[$key] ?? null) && ! $request->boolean("clear_{$key}")) {
                unset($data[$key]);
            }
        }

        $setting->fill($data);

        if (! $setting->isConfigured($setting->environment)) {
            return back()->withInput()->withErrors([
                $setting->environment.'_partner_id' => 'Isi Partner ID & Partner Key untuk mode yang dipilih.',
            ]);
        }

        $changed = array_keys($setting->getDirty());
        $setting->save();

        if ($changed) {
            // Hanya nama field yang dicatat, bukan nilainya (partner key rahasia).
            Activity::record('updated', 'shopee', 'Pengaturan Shopee diubah', $setting, [], ['field' => implode(', ', $changed)]);
        }

        return redirect()->route('marketplace.settings', ['tab' => 'shopee'])->with('success', 'Pengaturan Shopee disimpan.');
    }
}
