<?php

declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | kitcchu/user-interaction-tracker
 |--------------------------------------------------------------------------
 |
 | Publish this to config/user-interaction-tracker.php with:
 |   php artisan vendor:publish --tag=user-interaction-tracker-config
 |
 | Every key has a default (merged from the package) so the host app only
 | needs to override the values it cares about.
 */

return [

    /*
     | The HTTP endpoint that receives tracking events from the browser.
     | Change the path if it collides with an existing route.
     */
    'route' => [
        'path'       => env('UIT_ROUTE_PATH', 'api/track-interaction'),
        'name'       => 'user-interaction.track',
        'middleware' => [],                 // e.g. ['throttle:60,1']
    ],

    /*
     | Telegram delivery.
     */
    'telegram' => [
        'bot_token'        => env('TELEGRAM_BOT_TOKEN'),
        // Default group(s) for everything.
        'chat_ids'         => array_values(array_filter(explode(',', (string) env('TELEGRAM_TEAM_CHAT_IDS', '')))),
        // Optional dedicated group just for interaction digests.
        'interaction_chat' => env('TELEGRAM_INTERACTION_CHAT_ID'),
    ],

    /*
     | GeoIP. Two-tier: local MaxMind DB if present, otherwise ip-api.com.
     */
    'geoip' => [
        'maxmind_key' => env('MAXMIND_LICENSE_KEY'),
        'db_path'     => storage_path('app/geoip/GeoLite2-City.mmdb'),
    ],

    /*
     | Filtering — traffic excluded from digests (still stored for analysis).
     */
    'filter' => [
        // Comma-separated IPs / CIDR subnets (Google crawler subnets, scrapers,
        // your own IP for testing, …). Supports IPv4 + IPv6 CIDR.
        'exclude_ips' => array_values(array_filter(explode(',', (string) env('EXCLUDE_IPS', '')))),

        // Also exclude private/LAN/loopback ranges (192.168.*, 10.*, …).
        'filter_lan'  => env('UIT_FILTER_LAN', true),

        // User-Agent crawler tokens. Extend freely; matched case-insensitively.
        'bot_tokens' => [
            'googlebot', 'bingbot', 'bingpreview', 'slurp',
            'duckduckbot', 'baiduspider', 'yandexbot', 'yandex.com/bots',
            'facebookexternalhit', 'facebookcatalog', 'twitterbot', 'linkedinbot',
            'whatsapp', 'applebot', 'petalbot', 'sogou', 'exabot', 'mj12bot',
            'ahrefsbot', 'semrushbot', 'dotbot', 'bytespider',
            'googleother', 'googleimage', 'mediapartners-google',
            'ia_archiver', 'archive.org_bot',
            'gptbot', 'chatgpt-user', 'perplexitybot', 'claudebot',
            'crawler', 'spider',
        ],

        // Generic bot-ish regex applied after the token list.
        'bot_regex' => '/(bot|crawl|spider|slurp|scanner|fetcher|preview| indexer|httpclient|libwww|python-requests|curl|wget|go-http-client|java\/|okhttp|node-fetch|headless)/i',
    ],

    /*
     | Message rendering. Override to match your form's field names.
     */
    'labels' => [
        // Honeypots / internal fields never shown in Telegram.
        'honeypots' => ['_token', 'country', 'date', 'password'],

        // Field name => human label (used in digests).
        'fields' => [
            // Inquiry-style forms
            'name'                   => '姓名 / Name',
            'phone'                  => '電話 / Phone',
            'email'                  => '電郵 / Email',
            'service_plan'           => '服務計劃 / Service Plan',
            'service_plan_id'        => '服務計劃 / Service Plan',
            // link_click events
            'link_type'              => '連結類型 / Link',
            'link_text'              => '連結文字 / Text',
            'link_url'               => '網址 / URL',
            'schedule'               => '預約時間 / Schedule',
            'pickup_date'            => '上門日期 / Pickup Date',
            'pickup_time_slot'       => '上門時段 / Pickup Time',
            'material_delivery_date' => '送料日期 / Material Delivery Date',
            'district'               => '地區 / District',
            'street'                 => '街道 / Street',
            'volume'                 => '箱數 / Volume',
            'voucher'                => '推廣碼 / Voucher',
            'message'                => '留言 / Message',
            'use_whatsapp'           => 'WhatsApp',
            'packaging_materials'    => '包裝物料 / Packaging',
            'storage_items'          => '儲存物品 / Storage',
            'special_services'       => '特別服務 / Special Services',
            'special_instructions'   => '特別指示 / Special Instructions',
            'referral_source'        => '推薦來源 / Referral',
            'total_price'            => '總價 / Total',
            'address_id'             => '地址 / Address',
        ],
    ],

    /*
     | Scheduled digest. The cron expression runs in the host app's timezone.
     | Default: every 2 hours, 08:00–22:00 daily. Set schedule.enabled=false
     | to register it yourself (or disable scheduling entirely).
     */
    'schedule' => [
        'enabled' => env('UIT_SCHEDULE_ENABLED', true),
        'cron'    => env('UIT_SCHEDULE_CRON', '0 8,10,12,14,16,18,20,22 * * *'),
    ],

    /*
     | External link-click tracking. Keys are human labels, values are URL
     | substrings (matched anywhere in the href, case-insensitive). Clicks on
     | matching links fire a `link_click` event. Add/remove freely.
     */
    'link_clicks' => [
        'WhatsApp' => ['wa.me', 'api.whatsapp.com', 'web.whatsapp.com', 'whatsapp.com'],
        'Facebook' => ['facebook.com', 'fb.com', 'fb.me/', 'm.me/'],
        'Instagram' => ['instagram.com'],
        'Telegram' => ['t.me/'],
        'Phone'     => ['tel:'],
        'Email'     => ['mailto:'],
    ],

    /*
     | Database table name (override if "user_interactions" collides).
     */
    'table' => env('UIT_TABLE', 'user_interactions'),

    /*
     | Look-back behaviour. By default the digest processes ALL pending rows
     | (summarized_at IS NULL). Set a value in minutes to restrict it.
     */
    'lookback_minutes' => null,
];
