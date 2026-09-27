<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Site-content settings (contact info, homepage copy) that admins can
 * override from the dashboard, stored the same way as the pricing settings
 * in AdminShopController: a default in config('shop'), overridable by a
 * row in shop_settings.
 */
class SiteSettings
{
    public const KEYS = [
        'phone_number',
        'whatsapp_number',
        'free_delivery_text',
        'trusted_since_text',
        'store_address',
        'store_hours',
    ];

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return array_merge(
            collect(config('shop'))->only(self::KEYS)->all(),
            DB::table('shop_settings')->whereIn('key', self::KEYS)->pluck('value', 'key')->all(),
        );
    }
}
