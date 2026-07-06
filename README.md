# kitcchu/user-interaction-tracker

A Laravel package that captures **pre-submission** user interactions and form
input on your site, stores them for analysis, and sends a **periodic digest**
of real (non-bot) activity to a Telegram group — so your team can see what
prospects are doing *before* they finally submit (or abandon).

- ✅ Two form types: single-shot HTML inquiry forms **and** multi-step Vue forms
- ✅ GeoIP location (local MaxMind GeoLite2 DB, ip-api.com fallback)
- ✅ Bot / LAN / IP-subnet blocklist filtering
- ✅ 2-hourly Telegram digest (configurable cron), grouped by visitor session
- ✅ Full audit logs (scheduler, command output, sent digests)
- ✅ Security-first: the bot token never reaches the browser
- ✅ Framework-agnostic frontend (auto-inject Blade directive **or** Vue mixin)

---

## Requirements

- PHP ^8.1
- Laravel ^10.0 or ^11.0
- A Telegram bot token + a group the bot is a member of

---

## Installation

```bash
composer require kitcchu/user-interaction-tracker
```

Publish the assets you want:

```bash
php artisan vendor:publish --tag=user-interaction-tracker-config
php artisan vendor:publish --tag=user-interaction-tracker-migrations
php artisan vendor:publish --tag=user-interaction-tracker-public      # tracker.js → public/vendor/...
php artisan migrate
```

(Optionally `--tag=user-interaction-tracker-views` / `--tag=user-interaction-tracker-assets`
for the Vue mixin.)

Add to your `.env`:

```env
TELEGRAM_BOT_TOKEN=123456:ABC...
TELEGRAM_TEAM_CHAT_IDS=-1001234567890

# Optional GeoIP local database (https://www.maxmind.com/en/geolite2/signup)
MAXMIND_LICENSE_KEY=xxxxxxxxxxxx

# Optional IP/subnet blocklist (Google crawler subnets, your IP for testing, …)
EXCLUDE_IPS=74.125.150.0/24,218.252.185.99
```

### Trust your reverse proxy (so `Request::ip()` is the real client IP)

In `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));
})
```

### Schedule runner

The package auto-registers the digest schedule. Ensure a system cron runs the
Laravel scheduler every minute:

```
* * * * * cd /var/www/yourapp && php artisan schedule:run >> /dev/null 2>&1
```

Verify: `php artisan schedule:list` should list `interactions:summarize`.

---

## Integrating the frontend

### Option A — HTML / Blade pages (any stack)

Add the Blade directive to your layout (or per-page Blade file):

```blade
@userInteractionTracker(['page_id' => $page->id, 'page_title' => $page->title])
```

The bundled `tracker.js` then auto-detects any `<form class="uit-track">`
(or the legacy `id="exportEnquiryForm"`) and captures `page_view`,
`form_opened`, `field_focus`, `input_snapshot`, `form_abandoned`.

> The directive publishes `tracker.js` to `public/vendor/user-interaction-tracker/`
> via the `user-interaction-tracker-public` tag (already done above).

### Option B — Multi-step Vue forms

Publish the Vue assets:

```bash
php artisan vendor:publish --tag=user-interaction-tracker-assets
```

Then in your multi-step form component (Options API):

```js
import orderInteractionMixin from '@/js/vendor/user-interaction-tracker/order-interaction-mixin';

export default {
    mixins: [orderInteractionMixin],
    data() {
        return {
            trackingPageTitle: 'Create Order',
            currentStep: 1,
            order: { /* … */ },
        };
    },
    // …
};
```

The mixin reports `form_opened`, `step_change`, `order_submitted`, and
`form_abandoned` (on tab close via `sendBeacon`).

---

## Configuration

The published `config/user-interaction-tracker.php` has full inline docs. Key
options:

| Key | Purpose | Env |
|---|---|---|
| `route.path` | Endpoint path | `UIT_ROUTE_PATH` |
| `telegram.*` | Bot token + chat IDs | `TELEGRAM_BOT_TOKEN`, `TELEGRAM_TEAM_CHAT_IDS`, `TELEGRAM_INTERACTION_CHAT_ID` |
| `geoip.*` | MaxMind key + DB path | `MAXMIND_LICENSE_KEY` |
| `filter.exclude_ips` | IP/CIDR blocklist | `EXCLUDE_IPS` |
| `filter.bot_tokens` | Crawler UA tokens | — |
| `labels.fields` | Field → display label | — |
| `schedule.cron` | Digest cron expression | `UIT_SCHEDULE_CRON` |
| `table` | DB table name | `UIT_TABLE` |

---

## Commands

```bash
# Send a digest right now (processes all pending rows)
php artisan interactions:summarize

# Preview without sending
php artisan interactions:summarize --dry

# Download / refresh the GeoLite2 database
php artisan geoip:update
```

---

## Logs

| File | What |
|---|---|
| `storage/logs/scheduler.log` | cron firing `schedule:run` |
| `storage/logs/interaction-summary-output.log` | the digest command's output |
| `storage/logs/interaction-digests.log` | every sent digest (plain text) |

---

## Analysis queries

```php
use Kitcchu\UserInteractionTracker\Models\UserInteraction;

UserInteraction::forSession($sid)->orderBy('id')->get();         // a visitor's journey
UserInteraction::abandonedWithInput()->get();                     // real abandons
UserInteraction::pendingSummary()->count();                       // awaiting digest
UserInteraction::where('is_bot', false)->get();                   // real traffic only
```

---

## Security

- The browser **never** sees the Telegram bot token — it only POSTs to your own
  `/api/track-interaction` (or configured path).
- Honeypot fields (`country`, `date`, `_token`, `password`) are never stored or
  forwarded.
- Bot / LAN traffic is still recorded (for transparency) but flagged
  `is_bot=true` and excluded from digests.

---

## License

MIT. See [LICENSE](LICENSE).
