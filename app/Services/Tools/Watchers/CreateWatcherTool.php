<?php

namespace App\Services\Tools\Watchers;

use App\Enums\ToolOperationClass;
use App\Enums\WatcherCreatedBy;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\ConversationIntelligence\ReferenceResolver;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;
use App\Services\Watchers\Exceptions\WatcherException;
use App\Services\Watchers\WatcherDigestRequest;
use App\Services\Watchers\WatcherService;

final class CreateWatcherTool implements JarvisTool
{
    public const NAME = 'create_watcher';

    public function __construct(
        private readonly WatcherService $watchers,
        private readonly IntegrationAccountService $accounts,
        private readonly GoogleOAuthService $oauth,
        private readonly ReferenceResolver $references = new ReferenceResolver,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Creates an explicit Jarvis watcher for a future condition or a recurring Jarvis-performed check (not a reminder). Use when the user asked Jarvis to watch, check, or report something — including “проверяй каждое утро почту”. For “напомни мне проверить почту” use create_reminder instead. For “если эта задача завтра всё ещё будет открыта” use trigger_type=task_state, condition_type=status_equals (or still_open), condition.status=open, condition.hours=24, and the trusted recent task_id. Resolve stable ids. One-shot vs recurring must be explicit. Recurring Gmail morning digest: trigger_type=gmail_message, condition_type=new_item, mode=recurring, source.digest=true, source.schedule.kind=daily_local. Does not scan historical inbox/repo/calendar; first check only sets a baseline.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'name' => ['type' => 'STRING', 'description' => 'Short watcher name.'],
                    'trigger_type' => ['type' => 'STRING', 'description' => 'knowledge_event, task_state, reminder_state, time_condition, calendar_event, gmail_message, github_event.'],
                    'source_type' => ['type' => 'STRING', 'description' => 'knowledge_entity, project, task, reminder, gmail, calendar, github, time.'],
                    'condition_type' => ['type' => 'STRING', 'description' => 'Controlled condition, e.g. thread_received_reply, github_new_commit, overdue_by, entity_event_type.'],
                    'reaction_type' => ['type' => 'STRING', 'description' => 'notify, create_notification, create_reminder, create_task, run_internal_analysis, propose_action.'],
                    'mode' => ['type' => 'STRING', 'description' => 'one_shot or recurring. Recurring is required for “каждое утро / каждый день проверяй”.'],
                    'task_id' => ['type' => 'INTEGER', 'description' => 'Owned task id when watching a task/deadline.'],
                    'knowledge_entity_id' => ['type' => 'INTEGER', 'description' => 'Owned knowledge entity id when watching a timeline.'],
                    'project_id' => ['type' => 'INTEGER', 'description' => 'Owned project id when scoped.'],
                    'entity_name' => ['type' => 'STRING', 'description' => 'Fallback name/alias to resolve a knowledge entity, e.g. YFS.'],
                    'cooldown_seconds' => ['type' => 'INTEGER', 'description' => 'Minimum seconds between notifications.'],
                    'source' => ['type' => 'OBJECT', 'description' => 'Bounded source filters: query, sender, thread_id, repository, branch, event_id, calendar_id, event_type. For a Gmail morning digest set digest=true, query=in:inbox, and schedule.kind=daily_local with schedule.local_time (HH:MM). Do not pass integration_account_id or user_id.'],
                    'condition' => ['type' => 'OBJECT', 'description' => 'Bounded condition config: hours, status, event_type, sender, subject. For still-open-tomorrow set status=open and hours=24.'],
                    'reaction_config' => ['type' => 'OBJECT', 'description' => 'Bounded reaction config. For propose_action include tool name only — never execute it.'],
                ],
                'required' => [],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::WATCHERS, operation: ToolOperationClass::Write);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::WATCHERS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        try {
            $input = $this->normalizeInput($call, $context);
            $this->assertGmailReady($input, $context);
            $input['conversation_id'] = $context->conversation->id;
            $watcher = $this->watchers->create($context->user, $input, WatcherCreatedBy::Tool);
        } catch (WatcherException $exception) {
            $payload = [
                'success' => false,
                'error' => $exception->error,
                'message' => $this->userMessage($exception),
            ];

            if ($exception->candidates !== []) {
                $payload['candidates'] = $exception->candidates;
            }

            return ToolResult::failure($call->id, $this->name(), $payload);
        }

        $fresh = $watcher->fresh(['task', 'project', 'knowledgeEntity', 'reminder']) ?? $watcher;
        $serialized = $this->watchers->serialize($fresh, (string) ($context->user->timezone ?: 'UTC'));

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'watcher_id' => (int) $fresh->id,
            'task_id' => $fresh->task_id !== null ? (int) $fresh->task_id : null,
            'status' => $fresh->status->value,
            'mode' => $fresh->mode->value,
            'name' => $fresh->name,
            'description' => $serialized['description'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeInput(ToolCall $call, ToolExecutionContext $context): array
    {
        $input = $call->arguments;
        unset($input['user_id'], $input['integration_account_id']);
        if (is_array($input['source'] ?? null)) {
            unset($input['source']['user_id'], $input['source']['integration_account_id']);
        }

        $inbound = mb_strtolower(trim((string) ($context->inbound?->body ?? '')));
        $digest = WatcherDigestRequest::gmailMorningFromInbound($inbound, $context->user);
        if ($digest !== null) {
            return array_merge($digest, array_filter([
                'name' => trim((string) ($input['name'] ?? '')) !== '' ? $input['name'] : $digest['name'],
            ]));
        }

        $taskId = $this->resolveTaskId($call, $context);

        if ($taskId !== null) {
            $input['task_id'] = $taskId;
        } else {
            unset($input['task_id']);
        }

        $inbound = mb_strtolower(trim((string) ($context->inbound?->body ?? '')));
        $condition = mb_strtolower(trim((string) ($input['condition_type'] ?? '')));

        if ($condition === '' && $inbound !== '' && preg_match('/открыт|still open|останет/u', $inbound) === 1) {
            $input['condition_type'] = 'still_open';
            $condition = 'still_open';
        }

        if (! isset($input['trigger_type']) || $input['trigger_type'] === '') {
            if (isset($input['task_id'])) {
                $input['trigger_type'] = 'task_state';
            }
        }

        if (in_array($condition, ['still_open', 'remains_open', 'still_open_tomorrow', 'open_tomorrow', 'if_open', 'status_equals', 'status'], true)) {
            $conditionConfig = is_array($input['condition'] ?? null) ? $input['condition'] : [];
            $conditionConfig['status'] = $conditionConfig['status'] ?? $conditionConfig['expected'] ?? 'open';

            if (! isset($conditionConfig['hours']) && preg_match('/завтра|tomorrow/u', $inbound) === 1) {
                $conditionConfig['hours'] = 24;
            }

            $input['condition'] = $conditionConfig;
            $input['condition_type'] = 'status_equals';
            $input['mode'] = $input['mode'] ?? 'one_shot';
            $input['reaction_type'] = $input['reaction_type'] ?? 'notify';
        }

        if (($input['name'] ?? '') === '' && isset($input['task_id'])) {
            $input['name'] = 'Если задача останется открытой';
        }

        return $input;
    }

    private function resolveTaskId(ToolCall $call, ToolExecutionContext $context): ?int
    {
        $explicit = isset($call->arguments['task_id']) ? (int) $call->arguments['task_id'] : 0;
        $inbound = mb_strtolower(trim((string) ($context->inbound?->body ?? '')));
        $pronominal = $inbound !== '' && $this->references->hasDeictic($inbound);

        if ($explicit > 0) {
            if ($pronominal && $context->working !== null && ! $context->working->trustsTaskId($explicit)) {
                return $context->working->referredTask()?->id;
            }

            return $explicit;
        }

        if (! $pronominal || $context->working === null || ! $context->working->allowsTrustedMutation()) {
            return null;
        }

        $trusted = $context->working->referredTask();

        return $trusted?->id;
    }

    private function userMessage(WatcherException $exception): string
    {
        return match ($exception->error) {
            'invalid_config' => 'Не получилось поставить автоматизацию: не хватает задачи или условия. Если речь о конкретной задаче, назовите её или уточните, о какой из недавних.',
            'not_found' => 'Не нашёл задачу, за которой нужно следить.',
            'ambiguous' => 'Уточните, о какой задаче речь — сейчас их несколько.',
            'invalid_name' => 'Нужно короткое название для автоматизации.',
            'google_not_connected' => 'Могу это делать, но сначала нужно подключить Gmail.',
            'gmail_scope_required' => 'Нужно разрешить доступ к Gmail.',
            'capability_denied' => 'Эта автоматизация сейчас недоступна.',
            default => 'Не получилось создать автоматизацию.',
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertGmailReady(array $input, ToolExecutionContext $context): void
    {
        $trigger = mb_strtolower(trim((string) ($input['trigger_type'] ?? '')));
        $source = is_array($input['source'] ?? null) ? $input['source'] : [];
        $needsGmail = $trigger === 'gmail_message' || ($source['digest'] ?? false) === true;
        if (! $needsGmail) {
            return;
        }

        try {
            $account = $this->accounts->getActiveAccount($context->user, 'google');
        } catch (IntegrationException $exception) {
            if ($exception->error === 'forbidden') {
                throw new WatcherException('capability_denied', 'Gmail watchers are not available.');
            }

            throw new WatcherException($exception->error, $exception->getMessage());
        }

        if ($account === null) {
            throw new WatcherException('google_not_connected', 'Gmail is not connected.');
        }

        $scopes = is_array($account->scopes) ? $account->scopes : [];
        if (! $this->oauth->hasGmailReadScope($scopes)) {
            throw new WatcherException('gmail_scope_required', 'Gmail permission is required.');
        }
    }
}
