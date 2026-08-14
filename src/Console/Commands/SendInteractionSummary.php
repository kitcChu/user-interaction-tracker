<?php

declare(strict_types=1);

namespace Kitcchu\UserInteractionTracker\Console\Commands;

use Kitcchu\UserInteractionTracker\Models\UserInteraction;
use Kitcchu\UserInteractionTracker\Support\InteractionFormatter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Batches pending (non-bot) user interactions into a single Telegram digest.
 *
 *   php artisan interactions:summarize
 *
 * By default processes ALL pending rows (summarized_at IS NULL). Set a value
 * (minutes) to restrict to a look-back window.
 */
class SendInteractionSummary extends Command
{
    protected $signature = 'interactions:summarize
                            {--minutes= : Optional look-back window in minutes}
                            {--dry : Render the message but do not send/mark}';

    protected $description = 'Send a digest of pending user interactions to Telegram.';

    public function handle(InteractionFormatter $fmt): int
    {
        $dry = (bool) $this->option('dry');
        $cfg = config('user-interaction-tracker', []);

        $query = UserInteraction::pendingSummary()->orderBy('id');

        $lookback = $this->option('minutes') ?? ($cfg['lookback_minutes'] ?? null);
        if ($lookback) {
            $query->where('created_at', '>=', now()->subMinutes((int) $lookback));
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            if ($dry) {
                $this->info('No pending interactions.');
            }
            return self::SUCCESS;
        }

        $ids = $rows->modelKeys();
        $windowLabel = sprintf(
            '%s → %s',
            $rows->first()->created_at?->format('Y-m-d H:i'),
            now()->format('Y-m-d H:i')
        );

        $messages = $fmt->summary($rows, $windowLabel);

        $this->logDigest($rows, $messages);

        if ($dry) {
            $this->line("Would send {$rows->count()} interaction(s) (ids: " . implode(',', $ids) . ") in " . count($messages) . ' message(s):');
            foreach ($messages as $i => $m) {
                $this->line("--- message " . ($i + 1) . ' (' . mb_strlen($m) . " chars) ---");
                $this->line($m);
            }
            return self::SUCCESS;
        }

        // Send every chunk; only mark rows summarized if ALL succeed (avoids
        // silent data loss; a partial failure leaves rows pending for retry).
        $allSent = true;
        foreach ($messages as $i => $message) {
            if (!$this->sendToTelegram($message, $cfg)) {
                $allSent = false;
                $this->error("Telegram send failed on message " . ($i + 1) . '/' . count($messages) . '; rows will be retried next run.');
                break;
            }
        }

        if (!$allSent) {
            return self::FAILURE;
        }

        UserInteraction::whereIn('id', $ids)->update([
            'summarized_at' => now(),
            'telegram_sent' => true,
        ]);

        $this->info("Summary sent ({$rows->count()} interaction(s) in " . count($messages) . ' message(s)).');
        return self::SUCCESS;
    }

    private function sendToTelegram(string $message, array $cfg): bool
    {
        $botToken = $cfg['telegram']['bot_token'] ?? null;
        $chatIds = $this->targetChatIds($cfg);

        if (empty($botToken) || empty($chatIds)) {
            Log::warning('InteractionSummary: Telegram config missing, skipping.');
            return false;
        }

        $allOk = true;
        foreach ($chatIds as $chatId) {
            $chatId = trim((string) $chatId);
            if ($chatId === '') {
                continue;
            }
            try {
                $resp = Http::withoutVerifying()
                    ->timeout(10)
                    ->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                        'chat_id'                  => $chatId,
                        'text'                     => $message,
                        'parse_mode'               => 'HTML',
                        'disable_web_page_preview' => true,
                    ]);
                if (!$resp->successful()) {
                    Log::error("InteractionSummary: Telegram send failed for chat {$chatId}: " . $resp->body());
                    $allOk = false;
                }
            } catch (\Throwable $e) {
                Log::error('InteractionSummary: Telegram exception: ' . $e->getMessage());
                $allOk = false;
            }
        }
        return $allOk;
    }

    private function targetChatIds(array $cfg): array
    {
        $dedicated = $cfg['telegram']['interaction_chat'] ?? null;
        if ($dedicated) {
            return array_values(array_filter(explode(',', (string) $dedicated)));
        }
        return array_values(array_filter($cfg['telegram']['chat_ids'] ?? []));
    }

    /**
     * Best-effort audit log. Any failure is swallowed so it can never block
     * the Telegram send.
     */
    private function logDigest($rows, array $messages): void
    {
        $header = sprintf(
            "[%s] digest sent — %d interaction(s), ids: %s, %d message(s)\n",
            now()->toDateTimeString(),
            $rows->count(),
            implode(',', $rows->modelKeys()),
            count($messages)
        );
        $entry = $header;
        foreach ($messages as $m) {
            $entry .= strip_tags($m) . "\n" . str_repeat('—', 60) . "\n";
        }
        $entry .= "\n";

        try {
            file_put_contents(storage_path('logs/interaction-digests.log'), $entry, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            try {
                @file_put_contents('/tmp/interaction-digests.log', $entry, FILE_APPEND | LOCK_EX);
            } catch (\Throwable $_) {
                // totally swallowed
            }
        }
    }
}
