<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Staff extends Model
{
    protected $table = 'staff';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'role',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'order_staff')
            ->withTimestamps();
    }
}
