<?php

namespace App\Services\Integrations\Contracts;

use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\DTO\IntegrationStatus;

interface IntegrationProvider
{
    public function key(): string;

    public function displayName(): string;

    /**
     * @return list<string>
     */
    public function capabilities(): array;

    public function requiresAccount(): bool;

    public function supportsConnect(): bool;

    public function status(User $owner): IntegrationStatus;

    public function disconnect(IntegrationAccount $account): void;
}
