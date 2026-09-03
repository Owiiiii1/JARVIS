<?php

namespace App\Models;

use App\Enums\AiRoleKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'role_key',
    'provider',
    'model',
    'system_prompt',
    'parameters',
    'is_enabled',
])]
class AiRoleSetting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role_key' => AiRoleKey::class,
            'parameters' => 'array',
            'is_enabled' => 'boolean',
        ];
    }

    public function roleKey(): AiRoleKey
    {
        return $this->role_key instanceof AiRoleKey
            ? $this->role_key
            : AiRoleKey::from((string) $this->role_key);
    }
}
