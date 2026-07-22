<?php

declare(strict_types=1);

namespace Kitcchu\UserInteractionTracker\Http\Controllers;

use Kitcchu\UserInteractionTracker\Models\UserInteraction;
use Kitcchu\UserInteractionTracker\Services\GeoIpService;
use Kitcchu\UserInteractionTracker\Support\BotDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class UserInteractionController extends Controller
{
    public function __construct(
        private readonly GeoIpService $geo,
        private readonly BotDetector $bots,
        private readonly LogManager $log,
    ) {}

    public function track(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event'        => ['required', 'string', 'in:page_view,form_opened,field_focus,input_snapshot,form_abandoned,step_change,order_submitted,link_click'],
            'session_id'   => ['required', 'string', 'max:64'],
            'page_id'      => ['nullable', 'integer'],
            'page_title'   => ['nullable', 'string', 'max:255'],
            'url'          => ['nullable', 'string', 'max:500'],
            'fields'       => ['nullable', 'array'],
            'fields.*'     => ['nullable', 'string'],
            'focus_field'  => ['nullable', 'string', 'max:100'],
            'current_step' => ['nullable', 'integer', 'min:1', 'max:50'],
            'note'         => ['nullable', 'string', 'max:255'],
            'link_type'    => ['nullable', 'string', 'max:50'],
            'link_text'    => ['nullable', 'string', 'max:100'],
            'link_url'     => ['nullable', 'string', 'max:500'],
        ]);

        // Persist every event (real + bot) for analytics. Bots are flagged via
        // is_bot and excluded from the scheduled digest.
        $record = $this->persist($data['event'], $data, $request);

        // For link_click events, surface the link info in the standard `fields`
        // bucket so the digest renders it consistently.
        if ($data['event'] === 'link_click') {
            $extra = array_filter([
                'link_type' => $data['link_type'] ?? null,
                'link_text' => $data['link_text'] ?? null,
                'link_url'  => $data['link_url'] ?? null,
            ], fn ($v) => is_string($v) && trim($v) !== '');
            if ($extra && is_array($record->fields)) {
                $record->fields = array_merge($record->fields, $extra);
            } elseif ($extra) {
                $record->fields = $extra;
            }
            // Save without touching timestamps noise; keep id stable.
            if ($extra) {
                $record->saveQuietly();
            }
        }

        $bot = (bool) $record->is_bot;

        return response()->json([
            'ok'     => true,
            'id'     => $record->id,
            'is_bot' => $bot,
            'queued' => ! $bot,
        ]);
    }

    private function persist(string $event, array $data, Request $request): UserInteraction
    {
        $fields = array_filter($data['fields'] ?? [], fn ($v) => is_string($v) && trim($v) !== '');

        $ip    = $request->ip();
        $ua    = $request->userAgent();
        $isBot = $this->bots->shouldFilter($ip, $ua);
        // Skip GeoIP for bots (no point resolving crawler IPs every time).
        $location = (!$isBot && $ip) ? $this->geo->locate($ip) : null;

        try {
            return UserInteraction::create([
                'event'         => $event,
                'session_id'    => $data['session_id'],
                'ip_address'    => $ip,
                'location'      => $location,
                'user_agent'    => Str::limit($ua ?? '', 500),
                'referrer'      => Str::limit($request->headers->get('referer', '') ?? '', 500),
                'page_id'       => $data['page_id'] ?? null,
                'page_title'    => $data['page_title'] ?? null,
                'url'           => Str::limit($data['url'] ?? '', 500),
                'focus_field'   => $data['focus_field'] ?? null,
                'current_step'  => $data['current_step'] ?? null,
                'fields'        => $fields ?: null,
                'note'          => $data['note'] ?? null,
                'telegram_sent' => false,
                'is_bot'        => $isBot,
                // Bots never enter a digest.
                'summarized_at' => $isBot ? now() : null,
            ]);
        } catch (\Throwable $e) {
            $this->log->error('UserInteraction: failed to persist: ' . $e->getMessage());
            return new UserInteraction([
                'event' => $event, 'session_id' => $data['session_id'], 'is_bot' => $isBot,
            ]);
        }
    }
}
