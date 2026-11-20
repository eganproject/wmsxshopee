<?php

namespace App\Support;

use App\Models\ShopeeShop;
use App\Models\TiktokShop;

/**
 * Alamat etalase (halaman produk yang dilihat pembeli) di marketplace, dipakai
 * halaman Produk Channel supaya satu klik langsung membuka produknya di toko.
 */
class MarketplaceStorefront
{
    /** Domain Shopee per negara (region toko dari API). */
    private const SHOPEE_DOMAINS = [
        'ID' => 'shopee.co.id',
        'MY' => 'shopee.com.my',
        'SG' => 'shopee.sg',
        'TH' => 'shopee.co.th',
        'VN' => 'shopee.vn',
        'PH' => 'shopee.ph',
        'TW' => 'shopee.tw',
        'BR' => 'shopee.com.br',
    ];

    /** Halaman produk Shopee: https://shopee.co.id/product/{shop_id}/{item_id}. */
    public static function shopee(ShopeeShop $shop, int $itemId): string
    {
        $domain = self::SHOPEE_DOMAINS[strtoupper((string) $shop->region)] ?? self::SHOPEE_DOMAINS['ID'];

        return "https://{$domain}/product/{$shop->shop_id}/{$itemId}";
    }

    /**
     * Halaman produk TikTok Shop. Toko Indonesia tayang lewat Shop | Tokopedia,
     * negara lain lewat shop.tiktok.com.
     */
    public static function tiktok(TiktokShop $shop, string $productId): string
    {
        $domain = strtoupper((string) $shop->region) === 'ID' ? 'shop-id.tokopedia.com' : 'shop.tiktok.com';

        return "https://{$domain}/view/product/{$productId}";
    }
}
