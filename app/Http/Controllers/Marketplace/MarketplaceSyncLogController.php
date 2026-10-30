<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMarketplaceWebhook;
use App\Models\MarketplaceSyncLog;
use App\Models\MarketplaceWebhookEvent;
use App\Models\ShopeeShop;
use App\Services\MarketplaceShopSync;
use App\Services\Shopee\ShopeeException;
use App\Services\Shopee\ShopeeStockSync;
use App\Services\Tiktok\TiktokException;
use App\Services\Tiktok\TiktokStockSync;
use App\Support\BackgroundHealth;
use App\Support\FailedJobs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Riwayat sinkron per toko dan push yang masuk dari Shopee / TikTok, plus
 * tombol kirim ulang stok yang gagal dan proses ulang push yang gagal.
 */
class MarketplaceSyncLogController extends Controller
{
    public const STATUSES = ['success' => 'Berhasil', 'failed' => 'Gagal'];

    public const PUSH_STATUSES = [
        'pending' => 'Menunggu',
        'retrying' => 'Diulang',
        'processed' => 'Diproses',
        'ignored' => 'Diabaikan',
        'failed' => 'Gagal',
    ];

    public function index(Request $request): View
    {
        $user = $request->user();
        $shops = MarketplaceShopSync::shopsFor($user);
        $platforms = collect(['shopee', 'tiktok'])->filter(fn ($p) => $user->can("{$p}.view"))->values()->all();
        $tab = in_array($request->input('tab'), ['push', 'jobs'], true) ? $request->input('tab') : 'logs';
        [$platform, $shopId] = array_pad(explode('-', (string) $request->input('shop'), 2), 2, null);
        $shop = $shops->first(fn ($row) => $row['platform'] === $platform && (string) $row['shop']->id === $shopId);

        if ($tab === 'logs') {
            $rows = MarketplaceSyncLog::query()
                ->whereIn('platform', $platforms)
                ->when($shop, fn ($q) => $q->where('platform', $shop['platform'])->where('shop_ref', $shop['shop']->id))
                ->when(isset(self::STATUSES[$request->input('status')]), fn ($q) => $q->where('status', $request->input('status')))
                ->when(isset(MarketplaceSyncLog::PARTS[$request->input('part')]), fn ($q) => $q->where('part', 'like', '%'.$request->input('part').'%'))
                ->latest('started_at')->latest('id')
                ->paginate(50)->withQueryString();
        } elseif ($tab === 'jobs') {
            $rows = FailedJobs::paginate();
        } else {
            $rows = MarketplaceWebhookEvent::query()
                ->whereIn('platform', $platforms)
                ->when($shop, fn ($q) => $q->where('platform', $shop['platform'])->where('shop_ref', $shop['shop']->id))
                ->when(isset(self::PUSH_STATUSES[$request->input('status')]), fn ($q) => $q->where('status', $request->input('status')))
                ->when($request->filled('search'), fn ($q) => $q->where('order_id', 'like', '%'.trim((string) $request->input('search')).'%'))
                ->latest('id')
                ->paginate(50)->withQueryString();
        }

        return view('marketplace.sync-logs', [
            'tab' => $tab,
            'health' => BackgroundHealth::problems(),
            'rows' => $rows,
            'shops' => $shops,
            'shop' => $shop,
            'shopNames' => $shops->mapWithKeys(fn ($row) => [$row['platform'].'-'.$row['shop']->id => $row['shop']->displayName()]),
            'summary' => [
                'failed_today' => MarketplaceSyncLog::whereIn('platform', $platforms)->failed()->where('started_at', '>=', now()->startOfDay())->count(),
                'failed_stock' => $shops->sum(fn ($row) => $row['shop']->mappings()->whereNotNull('stock_error')->count()),
                'failed_push' => MarketplaceWebhookEvent::whereIn('platform', $platforms)->where('status', 'failed')->count(),
                'last_push' => MarketplaceWebhookEvent::whereIn('platform', $platforms)->max('created_at'),
                'failed_jobs' => FailedJobs::count(),
            ],
        ]);
    }

    /** Kirim ulang semua stok ke toko yang punya SKU gagal kirim (dan boleh disinkron user ini). */
    public function retryStock(Request $request, ShopeeStockSync $shopee, TiktokStockSync $tiktok): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAny(['shopee.sync', 'tiktok.sync']), 403, 'Anda tidak punya izin menyinkron toko marketplace.');

        $targets = MarketplaceShopSync::shopsFor($user)
            ->filter(fn ($row) => $row['shop']->isSyncable() && MarketplaceShopSync::canSync($user, $row['platform'], $row['shop']))
            ->filter(fn ($row) => $row['shop']->mappings()->whereNotNull('stock_error')->exists());

        if ($targets->isEmpty()) {
            return back()->with('success', 'Tidak ada stok gagal yang perlu dikirim ulang.');
        }

        @set_time_limit(600);
        $messages = [];
        $failed = false;

        foreach ($targets as ['platform' => $platform, 'shop' => $shop]) {
            try {
                $result = MarketplaceSyncLog::track($platform, $shop, 'stock', fn () => $shop instanceof ShopeeShop ? $shopee->push($shop) : $tiktok->push($shop),
                    fn (array $r) => [$r['failed'] ? 'failed' : 'success', 'Kirim ulang: '.($r['models'] ?? $r['skus']).' SKU dikirim'.($r['failed'] ? ', '.count($r['failed']).' gagal' : '')]);
                $left = count($result['failed']);
                $failed = $failed || $left > 0;
                $messages[] = $shop->displayName().': '.($left ? "{$left} masih gagal" : 'berhasil');
            } catch (ShopeeException|TiktokException $e) {
                $failed = true;
                $messages[] = $shop->displayName().': '.$e->getMessage();
            }
        }

        return back()->with($failed ? 'error' : 'success', 'Kirim ulang stok. '.implode(' | ', $messages));
    }

    /** Proses ulang push yang gagal (dalam 7 hari terakhir). */
    public function retryPush(Request $request): RedirectResponse
    {
        $platforms = collect(['shopee', 'tiktok'])->filter(fn ($p) => $request->user()->can("{$p}.sync"))->values()->all();
        abort_if($platforms === [], 403, 'Anda tidak punya izin menyinkron toko marketplace.');

        $events = MarketplaceWebhookEvent::whereIn('platform', $platforms)->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(7))->get();

        foreach ($events as $event) {
            $event->update(['status' => 'pending', 'attempts' => 0, 'error' => null]);
            ProcessMarketplaceWebhook::dispatch($event->id);
        }

        return back()->with('success', $events->isEmpty() ? 'Tidak ada push gagal yang perlu diproses ulang.' : "{$events->count()} push dimasukkan ke antrean untuk diproses ulang.");
    }

    /** Ulangi satu job gagal, atau semua bila tanpa id. */
    public function retryJob(Request $request, ?string $job = null): RedirectResponse
    {
        abort_unless($request->user()->canAny(['shopee.sync', 'tiktok.sync']), 403, 'Anda tidak punya izin menyinkron toko marketplace.');

        $count = FailedJobs::retry($job);

        return back()->with('success', $count ? "{$count} job dimasukkan lagi ke antrean." : 'Tidak ada job gagal yang perlu diulang.');
    }

    /** Hapus satu job gagal dari daftar, atau semua bila tanpa id. */
    public function forgetJob(Request $request, ?string $job = null): RedirectResponse
    {
        abort_unless($request->user()->canAny(['shopee.sync', 'tiktok.sync']), 403, 'Anda tidak punya izin menyinkron toko marketplace.');

        $count = FailedJobs::forget($job);

        return back()->with('success', "{$count} job gagal dihapus dari daftar.");
    }
}
