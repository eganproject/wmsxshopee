<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\LabelSetting;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Pengaturan cetak resi: QR nomor resi (AWB) di label Shopee, TikTok & pesanan manual. */
class LabelSettingController extends Controller
{
    public function edit(): View
    {
        return view('sales.label-settings.edit', ['setting' => LabelSetting::current()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [
            'qr_enabled' => ['boolean'],
            'qr_size_mm' => ['required', 'integer', 'min:10', 'max:40'],
            'manual_qr' => ['boolean'],
        ];
        foreach (array_keys(LabelSetting::PLATFORMS) as $platform) {
            $rules["{$platform}_qr"] = ['boolean'];
            $rules["{$platform}_qr_position"] = ['required', Rule::in(array_keys(LabelSetting::POSITIONS))];
            $rules["{$platform}_qr_margin_x_mm"] = ['required', 'numeric', 'min:0', 'max:100'];
            $rules["{$platform}_qr_margin_y_mm"] = ['required', 'numeric', 'min:0', 'max:140'];
        }

        $data = $request->validate($rules, [], [
            'qr_size_mm' => 'ukuran QR',
            'shopee_qr_margin_x_mm' => 'jarak samping Shopee',
            'shopee_qr_margin_y_mm' => 'jarak atas/bawah Shopee',
            'tiktok_qr_margin_x_mm' => 'jarak samping TikTok',
            'tiktok_qr_margin_y_mm' => 'jarak atas/bawah TikTok',
        ]);

        foreach (['qr_enabled', 'manual_qr', 'shopee_qr', 'tiktok_qr'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        $setting = LabelSetting::current();
        $setting->fill($data);
        $changed = array_keys($setting->getDirty());
        $setting->save();

        if ($changed) {
            Activity::record('updated', 'sales', 'Pengaturan cetak resi diubah', $setting, [], ['field' => implode(', ', $changed)]);
        }

        return redirect()->route('sales.label-settings.edit')->with('success', 'Pengaturan cetak resi disimpan.');
    }
}
