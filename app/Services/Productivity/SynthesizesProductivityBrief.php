<?php

namespace App\Services\Productivity;

use App\Models\User;

interface SynthesizesProductivityBrief
{
    /**
     * @param  array<string, mixed>  $sources
     */
    public function synthesize(User $user, string $mode, string $deterministic, array $sources): ?string;
}
