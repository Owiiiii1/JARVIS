<?php

namespace App\Services\Integrations;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Integrations\Contracts\IntegrationProvider;
use App\Services\Integrations\DTO\IntegrationStatus;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Users\UserCapability;

final class IntegrationRegistry
{
    /**
     * @param  list<IntegrationProvider>  $providers
     */
    public function __construct(
        private readonly array $providers,
    ) {}

    public function get(string $key): ?IntegrationProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->key() === $key) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @return list<IntegrationProvider>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * @return list<IntegrationStatus>
     */
    public function listForOwner(User $owner): array
    {
        $this->assertOwner($owner);

        return array_map(
            static fn (IntegrationProvider $provider): IntegrationStatus => $provider->status($owner),
            $this->providers,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function summariesForOwner(User $owner): array
    {
        return array_map(
            static fn (IntegrationStatus $status): array => $status->toArray(),
            $this->listForOwner($owner),
        );
    }

    private function assertOwner(User $owner): void
    {
        if (! $owner->isActive()
            || $owner->role !== UserRole::Owner
            || ! $owner->canUseCapability(UserCapability::INTEGRATIONS_ADMIN)) {
            throw new IntegrationException('forbidden', 'Integrations are owner-only.');
        }
    }
}
