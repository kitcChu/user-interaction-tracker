<?php

declare(strict_types=1);

namespace Kitcchu\UserInteractionTracker\Models;

use Illuminate\Database\Eloquent\Model;

class UserInteraction extends Model
{
    /** @var string|null Set by the service provider from config('user-interaction-tracker.table') */
    protected static ?string $customTable = null;

    protected $guarded = [];

    protected $casts = [
        'fields'        => 'array',
        'location'      => 'array',
        'telegram_sent' => 'boolean',
        'is_bot'        => 'boolean',
    ];

    public static function setTableName(string $name): void
    {
        static::$customTable = $name;
    }

    public function getTable(): string
    {
        return static::$customTable ?? parent::getTable();
    }

    /* ----- Query scopes for analysis ----- */

    public function scopeForSession($q, string $sessionId)
    {
        return $q->where('session_id', $sessionId);
    }

    public function scopeAbandonedWithInput($q)
    {
        return $q->where('event', 'form_abandoned')
                 ->whereNotNull('fields')
                 ->where('fields', '!=', '[]');
    }

    /** Real (non-bot) interactions not yet included in a summary digest. */
    public function scopePendingSummary($q)
    {
        return $q->where('is_bot', false)->whereNull('summarized_at');
    }
}
