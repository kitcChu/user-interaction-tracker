<?php

declare(strict_types=1);

namespace Kitcchu\UserInteractionTracker;

use Kitcchu\UserInteractionTracker\Console\Commands\SendInteractionSummary;
use Kitcchu\UserInteractionTracker\Console\Commands\UpdateGeoIpDatabase;
use Kitcchu\UserInteractionTracker\Models\UserInteraction;
use Kitcchu\UserInteractionTracker\Services\GeoIpService;
use Kitcchu\UserInteractionTracker\Support\BotDetector;
use Kitcchu\UserInteractionTracker\Support\InteractionFormatter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class UserInteractionTrackerServiceProvider extends ServiceProvider
{
    public function boot(Schedule $schedule): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'user-interaction-tracker-migrations');

            $this->publishes([
                __DIR__ . '/../config/user-interaction-tracker.php' => config_path('user-interaction-tracker.php'),
            ], 'user-interaction-tracker-config');

            $this->publishes([
                __DIR__ . '/../resources/js/' => resource_path('js/vendor/user-interaction-tracker'),
            ], 'user-interaction-tracker-assets');

            $this->publishes([
                __DIR__ . '/../resources/views/' => resource_path('views/vendor/user-interaction-tracker'),
            ], 'user-interaction-tracker-views');

            $this->publishes([
                __DIR__ . '/../resources/js/tracker.js' => public_path('vendor/user-interaction-tracker/tracker.js'),
            ], 'user-interaction-tracker-public');
        }

        $this->mergeConfigFrom(__DIR__ . '/../config/user-interaction-tracker.php', 'user-interaction-tracker');

        // Bind a config-driven table name to the model.
        UserInteraction::setTableName(config('user-interaction-tracker.table'));

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'user-interaction-tracker');

        // Schedule the digest (opt-out via config).
        if (config('user-interaction-tracker.schedule.enabled', true)) {
            $schedule->command(SendInteractionSummary::class)
                ->cron(config('user-interaction-tracker.schedule.cron'))
                ->name('interactions-summary')
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/interaction-summary-output.log'));
        }

        // Blade directive: @userInteractionTracker renders the tracker <script>.
        // Optional page meta: @userInteractionTracker(['page_id' => $page->id, 'page_title' => $page->title])
        // The JS auto-detects any <form class="uit-track"> (or legacy #exportEnquiryForm).
        Blade::directive('userInteractionTracker', function ($pageMeta = null) {
            return '<?php echo view("user-interaction-tracker::component", ["pageMeta" => ' . ($pageMeta ?: '[]') . '])->render(); ?>';
        });
    }

    public function register(): void
    {
        $this->app->singleton(GeoIpService::class, function ($app) {
            return new GeoIpService(
                dbPath: config('user-interaction-tracker.geoip.db_path', storage_path('app/geoip/GeoLite2-City.mmdb')),
            );
        });

        $this->app->singleton(BotDetector::class, function ($app) {
            return BotDetector::fromConfig(config('user-interaction-tracker.filter', []));
        });

        $this->app->singleton(InteractionFormatter::class, function ($app) {
            return InteractionFormatter::fromConfig(config('user-interaction-tracker.labels', []));
        });

        $this->commands([
            SendInteractionSummary::class,
            UpdateGeoIpDatabase::class,
        ]);
    }
}
