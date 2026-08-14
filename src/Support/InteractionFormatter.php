<?php

declare(strict_types=1);

namespace Kitcchu\UserInteractionTracker\Support;

use Illuminate\Support\Collection;

/**
 * Formats UserInteraction rows into a compact Telegram HTML digest.
 */
class InteractionFormatter
{
    public function __construct(
        private readonly array $honeypots = ['_token', 'country', 'date', 'password'],
        private readonly array $fieldLabels = [],
    ) {}

    /**
     * @param array{honeypots?: string[], fields?: array<string,string>} $cfg
     */
    public static function fromConfig(array $cfg): self
    {
        return new self(
            honeypots: $cfg['honeypots'] ?? ['_token', 'country', 'date', 'password'],
            fieldLabels: $cfg['fields'] ?? [],
        );
    }

    public function eventMeta(string $event): array
    {
        return match ($event) {
            'page_view'       => ['🌐', '瀏覽頁面'],
            'form_opened'     => ['👁️', '打開表單'],
            'field_focus'     => ['👆', '填寫欄位'],
            'input_snapshot'  => ['✏️', '輸入中'],
            'form_abandoned'  => ['🚪', '離開未提交'],
            'step_change'     => ['👣', '進入下一步'],
            'order_submitted' => ['🎉', '訂單已提交'],
            'link_click'      => ['🔗', '點擊連結'],
            default           => ['ℹ️', '互動'],
        };
    }

    /**
     * Render rows into one or more HTML-balanced Telegram messages.
     *
     * Long digests are split at session-block boundaries (every block is
     * self-contained HTML) so NO message is ever truncated mid-tag — that was
     * the bug that made Telegram reject large digests with
     * "can't parse entities: Unclosed end tag".
     *
     * @return list<string> One message per chunk, each <4096 chars & balanced.
     */
    public function summary(Collection $rows, string $windowLabel): array
    {
        $footer = "\n────────────────\n<i>🔁 自動摘要 / auto digest</i>";
        $sep = "\n────────────────\n";

        $max = 3500;                                  // chars, safely < Telegram's 4096
        $budget = $max - mb_strlen($footer);

        $chunks = [];
        $current = $this->buildHeader($rows, $windowLabel);
        $part = 1;

        foreach ($rows->groupBy('session_id') as $sid => $sessionRows) {
            $block = $this->renderSessionBlock((string) $sid, $sessionRows);

            // Start a new chunk if adding this block would overflow.
            if ($current !== $this->buildHeader($rows, $windowLabel)
                && mb_strlen($current) + mb_strlen($sep) + mb_strlen($block) > $budget
            ) {
                $chunks[] = $current . $footer;
                $part++;
                $current = "📊 <b>用戶互動摘要 (續 {$part} / cont'd)</b>\n";
            }

            $current .= $sep . $block;
        }
        $chunks[] = $current . $footer;

        return $chunks;
    }

    private function buildHeader(Collection $rows, string $windowLabel): string
    {
        $total = $rows->count();
        $sessions = $rows->pluck('session_id')->unique()->count();
        $highlights = $this->countHighlights($rows);

        $msg = "📊 <b>用戶互動摘要 / Interaction Summary</b>\n";
        $msg .= "⏱️ {$this->esc($windowLabel)}\n";
        $msg .= "👥 {$sessions} 訪客 / visitor(s) · {$total} 互動 / interaction(s)";
        if ($highlights > 0) {
            $msg .= " · ⚡ {$highlights} 高價值 / high-value";
        }
        return $msg . "\n";
    }

    private function renderSessionBlock(string $sid, Collection $rows): string
    {
        $rows = $rows->sortBy('id');
        $first = $rows->first();

        $loc = $this->locationShort($first->location ?? null);
        $ip = $first->ip_address ?: '?';
        $flag = $this->flagEmoji($first->location['country_code'] ?? null);
        $title = $first->page_title ?: '—';

        $out = "🧑 <b>Session</b> {$this->esc(mb_substr($sid, 0, 10))}\n";
        $out .= "{$flag} {$this->esc($loc)} · 🌐 {$this->esc($ip)}\n";
        $out .= "📄 {$this->esc($title)}\n";

        // Cap a single session block so it can never blow the per-message budget.
        $blockMax = 2000;
        foreach ($rows as $r) {
            [$emoji, $label] = $this->eventMeta($r->event);
            $time = $r->created_at ? $r->created_at->format('H:i:s') : '';
            $line = "{$emoji} {$time} {$label}";
            if ($r->current_step) {
                $line .= " · step {$r->current_step}";
            }
            if ($r->focus_field) {
                $line .= " · {$this->esc($r->focus_field)}";
            }
            $candidate = $out . "{$line}\n";
            if (!empty($r->fields)) {
                $candidate .= $this->renderFieldsInline($r->fields);
            }
            if (mb_strlen($candidate) > $blockMax) {
                $out .= "   …(更多事件省略 / more events omitted)\n";
                break;
            }
            $out = $candidate;
        }
        return $out;
    }

    private function renderFieldsInline(array $fields): string
    {
        $parts = [];
        foreach ($fields as $key => $value) {
            if (in_array($key, $this->honeypots, true)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > 80) {
                $value = mb_substr($value, 0, 80) . '…';
            }
            $label = $this->fieldLabels[$key] ?? $key;
            $parts[] = "{$label}={$value}";
        }
        if (empty($parts)) {
            return '';
        }
        return '   ✏️ ' . $this->esc(implode(' | ', $parts)) . "\n";
    }

    private function countHighlights(Collection $rows): int
    {
        return $rows->filter(function ($r) {
            if (in_array($r->event, ['order_submitted', 'link_click'], true)) {
                return true;
            }
            return $r->event === 'form_abandoned' && !empty($r->fields);
        })->count();
    }

    private function locationShort(?array $loc): string
    {
        if (!$loc) {
            return '未知 / unknown';
        }
        $parts = array_filter([$loc['city'] ?? null, $loc['country'] ?? null]);
        return $parts ? implode(', ', $parts) : '未知 / unknown';
    }

    public function flagEmoji(?string $code): string
    {
        $code = strtoupper(trim((string) $code));
        if (strlen($code) !== 2 || !ctype_alpha($code)) {
            return '';
        }
        $out = '';
        foreach (str_split($code) as $ch) {
            $out .= mb_chr(ord($ch) - ord('A') + 0x1F1E6, 'UTF-8');
        }
        return $out . ' ';
    }

    public function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
