<?php

namespace App\Models;

use App\Enums\TelegramGroupStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use DateTimeZone;
use Throwable;

#[Fillable([
    'telegram_chat_id',
    'conversation_id',
    'title',
    'username',
    'chat_type',
    'status',
    'timezone',
    'first_seen_at',
    'last_seen_at',
    'last_message_at',
    'message_count',
    'settings',
    'metadata',
])]
class TelegramGroup extends Model
{
    public const MODE_PERSIST_ONLY = 'persist_only';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TelegramGroupStatus::class,
            'settings' => 'array',
            'metadata' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_message_at' => 'datetime',
            'message_count' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(TelegramGroupParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_groups')
            ->withPivot(['attached_at', 'metadata']);
    }

    public function knowledge(): HasMany
    {
        return $this->hasMany(TelegramGroupKnowledge::class);
    }

    public function analysisRuns(): HasMany
    {
        return $this->hasMany(TelegramGroupAnalysisRun::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', TelegramGroupStatus::Left);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', TelegramGroupStatus::Left);
    }

    public function isArchived(): bool
    {
        return $this->status === TelegramGroupStatus::Left;
    }

    public function mode(): string
    {
        $mode = $this->settings['mode'] ?? self::MODE_PERSIST_ONLY;

        return is_string($mode) && $mode !== '' ? $mode : self::MODE_PERSIST_ONLY;
    }

    public function isPersistOnly(): bool
    {
        return $this->mode() === self::MODE_PERSIST_ONLY;
    }

    public function effectiveTimezone(?string $ownerTimezone = null): string
    {
        if (filled($this->timezone) && self::isValidTimezone((string) $this->timezone)) {
            return (string) $this->timezone;
        }

        if (filled($ownerTimezone) && self::isValidTimezone($ownerTimezone)) {
            return $ownerTimezone;
        }

        return (string) config('app.timezone');
    }

    public static function isValidTimezone(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
