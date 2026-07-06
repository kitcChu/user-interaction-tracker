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
            default           => ['ℹ️', '互動'],
        };
    }

    public function summary(Collection $rows, string $windowLabel): string
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
        $msg .= "\n";

        foreach ($rows->groupBy('session_id') as $sid => $sessionRows) {
            $msg .= "\n────────────────\n";
            $msg .= $this->renderSessionBlock((string) $sid, $sessionRows);
        }

        $msg .= "\n────────────────\n";
        $msg .= "<i>🔁 自動摘要 / auto digest</i>";

        if (mb_strlen($msg) > 3900) {
            $msg = mb_substr($msg, 0, 3850) . "\n…<i>(截斷 / truncated)</i>";
        }
        return $msg;
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
            $out .= "{$line}\n";
            if (!empty($r->fields)) {
                $out .= $this->renderFieldsInline($r->fields);
            }
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
            if ($r->event === 'order_submitted') {
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
