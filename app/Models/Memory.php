<?php

namespace App\Models;

use App\Enums\MemoryKind;
use App\Enums\MemoryScope;
use App\Enums\MemoryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'scope',
    'kind',
    'content',
    'normalized_key',
    'confidence',
    'status',
    'valid_from',
    'valid_until',
    'first_seen_at',
    'last_confirmed_at',
    'metadata',
])]
class Memory extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => MemoryScope::class,
            'kind' => MemoryKind::class,
            'status' => MemoryStatus::class,
            'confidence' => 'float',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime',
            'last_confirmed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(MemorySource::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(MemoryRevision::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_memories')
            ->withPivot(['attached_at', 'metadata']);
    }
}
