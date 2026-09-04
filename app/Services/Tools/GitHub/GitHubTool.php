<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Models\IntegrationAccount;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\GitHub\GitHubApiService;
use App\Services\Integrations\GitHub\GitHubOAuthService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

abstract class GitHubTool implements JarvisTool
{
    public function __construct(
        protected readonly GitHubApiService $github,
        protected readonly IntegrationAccountService $accounts,
        protected readonly GitHubOAuthService $oauth,
    ) {}

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::GITHUB);
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::GITHUB,
            operation: $this->operation(),
            provider: 'github',
            confirmationHint: $this->confirmationHint(),
        );
    }

    abstract protected function operation(): ToolOperationClass;

    protected function confirmationHint(): ?string
    {
        return null;
    }

    public function assertReady(ToolExecutionContext $context): void
    {
        $this->resolveAccount($context);
    }

    protected function resolveAccount(ToolExecutionContext $context): IntegrationAccount
    {
        try {
            $account = $this->accounts->getActiveAccount($context->user, 'github');
        } catch (IntegrationException $exception) {
            if ($exception->error === 'forbidden') {
                throw new IntegrationException('github_not_connected', 'GitHub is not connected.');
            }

            throw $exception;
        }

        if ($account === null) {
            throw new IntegrationException('github_not_connected', 'GitHub is not connected.');
        }

        $scopes = is_array($account->scopes) ? $account->scopes : [];
        if (! $this->oauth->hasRepoScope($scopes)) {
            throw new IntegrationException('github_scope_required', 'GitHub repository permission is required.');
        }

        return $account;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function ok(ToolCall $call, array $payload): ToolResult
    {
        return ToolResult::success($call->id, $this->name(), array_merge(['success' => true], $payload));
    }

    protected function repository(ToolCall $call): string
    {
        $repository = trim((string) ($call->arguments['repository'] ?? ''));
        if ($repository === '') {
            throw new IntegrationException('github_validation_failed', 'Repository is required.');
        }

        return $repository;
    }

    protected function optionalInt(ToolCall $call, string $key): ?int
    {
        if (! array_key_exists($key, $call->arguments) || $call->arguments[$key] === null || $call->arguments[$key] === '') {
            return null;
        }

        return (int) $call->arguments[$key];
    }

    protected function optionalString(ToolCall $call, string $key): ?string
    {
        if (! array_key_exists($key, $call->arguments) || $call->arguments[$key] === null) {
            return null;
        }

        $value = trim((string) $call->arguments[$key]);

        return $value === '' ? null : $value;
    }
}
