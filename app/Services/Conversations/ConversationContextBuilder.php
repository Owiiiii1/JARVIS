<?php

namespace App\Services\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Models\AiRoleSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\UserAiSetting;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Memory\DTO\MemoryContextPackage;
use App\Services\Memory\PersonalMemoryRetriever;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\GetProjectContextTool;
use App\Services\Tools\SearchConversationHistoryTool;
use App\Services\Tools\SearchGroupKnowledgeTool;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;

final class ConversationContextBuilder
{
    public const DEFAULT_RECENT_LIMIT = 30;

    public const MIN_RECENT_LIMIT = 5;

    public const MAX_RECENT_LIMIT = 40;

    public function __construct(
        private readonly PersonalMemoryRetriever $memoryRetriever,
    ) {}

    /**
     * @param  list<ToolDefinition>  $tools
     * @return array{system_prompt: string, messages: list<AiChatMessage>}
     */
    public function build(
        User $user,
        Conversation $conversation,
        AiRoleSetting $configuration,
        ?Message $currentInbound = null,
        ?string $applicationEvent = null,
        array $tools = [],
    ): array {
        $sections = [trim((string) $configuration->system_prompt)];
        $sections[] = $this->currentTimeContext($user);
        $toolContext = $this->toolContext($tools);

        if ($toolContext !== null) {
            $sections[] = $toolContext;
        }

        $generalPrompt = $this->generalPromptFor($user);

        if ($generalPrompt !== null) {
            $sections[] = "User General Prompt:\n".$generalPrompt;
        }

        $memory = $this->memoryRetriever->retrieve(
            $user,
            $conversation,
            $currentInbound?->body,
        );
        $memorySections = $this->memorySections($memory, $conversation, $configuration);

        if ($memorySections !== []) {
            $sections = array_merge($sections, $memorySections);
        }

        if (filled($applicationEvent)) {
            $sections[] = "Application event:\n".trim($applicationEvent);
        }

        $systemPrompt = trim(implode("\n\n", array_filter($sections)));
        $recent = $this->recentSemanticMessages($conversation, $configuration, $currentInbound);

        return [
            'system_prompt' => $systemPrompt,
            'messages' => $recent,
        ];
    }

    private function generalPromptFor(User $user): ?string
    {
        $prompt = UserAiSetting::query()
            ->where('user_id', $user->id)
            ->value('general_prompt');

        if (! is_string($prompt)) {
            return null;
        }

        $prompt = trim($prompt);

        return $prompt === '' ? null : $prompt;
    }

    private function currentTimeContext(User $user): string
    {
        $timezone = (string) ($user->timezone ?: 'UTC');

        try {
            new DateTimeZone($timezone);
            $now = CarbonImmutable::now($timezone);
        } catch (Exception) {
            $timezone = 'UTC';
            $now = CarbonImmutable::now('UTC');
        }

        return "Current user local time:\n".$now->format('Y-m-d\\TH:i:sP')."\n\nUser timezone:\n".$timezone;
    }

    /**
     * @param  list<ToolDefinition>  $tools
     */
    private function toolContext(array $tools): ?string
    {
        if ($tools === []) {
            return null;
        }

        $names = array_map(static fn (ToolDefinition $tool): string => $tool->name, $tools);
        $lines = [
            'Available tools: '.implode(', ', $names).'.',
            'Use a tool only when the user request requires it. Never invent tools.',
            'Never pass user_id or conversation_id as tool arguments. Identity comes from the current conversation.',
        ];

        if (in_array(CreateReminderTool::NAME, $names, true)) {
            $lines[] = 'create_reminder creates a one-time Telegram reminder. Call it when the user asks to be reminded and the time is exact (clock time or a relative duration such as "in 2 minutes").';
            $lines[] = 'Only call create_reminder when the current user message is itself a reminder request. Follow-ups such as "ты тут?" are not reminder requests.';
            $lines[] = 'If the day is known but the clock time is missing, ask "Во сколько напомнить?" and do not call the tool. Do not invent 09:00 or another default time.';
            $lines[] = 'Dayparts such as "tomorrow morning" without a clock time are not exact — ask.';
            $lines[] = 'Recurring reminders are not supported yet. If the user asks for a repeating reminder, say so and do not create a one-time reminder as a substitute.';
            $lines[] = 'If create_reminder returns error telegram_not_connected, tell the user: Для получения напоминаний сначала подключите Telegram.';
            $lines[] = 'After a successful create_reminder, confirm in natural language using the returned local time. Do not mention tool names.';
        }

        if (in_array(SearchConversationHistoryTool::NAME, $names, true)) {
            $lines[] = 'search_conversation_history looks up snippets from this user’s own past chats. Use it when the user asks about a previous conversation, decision, or detail that is not already in context.';
            $lines[] = 'Do not assume raw messages from other chats are already available. Other chats may appear only as short summaries.';
            $lines[] = 'Never pass user_id. Never search another user’s history.';
        }

        if (in_array(GetProjectContextTool::NAME, $names, true)) {
            $lines[] = 'get_project_context loads compact derived context for one of the current user’s projects. Call it when the user asks about a named project.';
            $lines[] = 'Do not assume all projects are already in context. Do not invent project knowledge if the tool returns no attached topics, memories, or summaries.';
            $lines[] = 'Project context is summary-first. Use search_conversation_history only if a specific raw detail is needed after project context.';
        }

        if (in_array(SearchGroupKnowledgeTool::NAME, $names, true)) {
            $lines[] = 'search_group_knowledge looks up Telegram group summaries, decisions, tasks, events, and bounded raw snippets. Call it when the user asks about groups, group decisions, group tasks, or what someone said in a group.';
            $lines[] = 'Group data is never already in context. Do not invent group discussions if the tool returns no matches.';
            $lines[] = 'If analysis_status is queued or partial, say that the current answer uses available data and a fuller analysis may still be running. Do not wait.';
            $lines[] = 'Use get_project_context for overall project context. Use search_group_knowledge for group activity specifically.';
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function memorySections(MemoryContextPackage $package, Conversation $conversation, AiRoleSetting $configuration): array
    {
        $sections = [];

        if ($package->memories !== []) {
            $lines = ['Relevant personal memory:'];

            foreach ($package->memories as $memory) {
                $lines[] = '- '.$memory->content;
            }

            $sections[] = implode("\n", $lines);
        }

        $profile = trim((string) ($package->profile?->summary ?? ''));

        if ($profile !== '') {
            $sections[] = "User profile:\n".$profile;
        }

        if ($package->crossChatSummaries !== []) {
            $lines = ['Relevant summaries from other chats of this user:'];

            foreach ($package->crossChatSummaries as $summary) {
                $title = $summary->conversation?->title ?: 'Chat';
                $lines[] = '- '.$title.': '.$summary->summary;
            }

            $sections[] = implode("\n", $lines);
        }

        if ($package->currentSummary !== null && $this->shouldIncludeCurrentSummary($conversation, $configuration)) {
            $sections[] = "Current conversation summary:\n".$package->currentSummary->summary;
        }

        return $sections;
    }

    private function shouldIncludeCurrentSummary(Conversation $conversation, AiRoleSetting $configuration): bool
    {
        $recentLimit = $this->recentLimit($configuration);
        $count = Message::query()
            ->where('conversation_id', $conversation->id)
            ->count();

        return $count > $recentLimit;
    }

    /**
     * @return list<AiChatMessage>
     */
    private function recentSemanticMessages(
        Conversation $conversation,
        AiRoleSetting $configuration,
        ?Message $currentInbound,
    ): array {
        $limit = $this->recentLimit($configuration);

        $rows = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit * 3)
            ->get();

        $selected = [];

        foreach ($rows as $message) {
            if ($this->isUnfinishedHistoricalInbound($message, $currentInbound)) {
                continue;
            }

            if (! $this->isSemanticDialogue($message)) {
                continue;
            }

            $selected[] = $message;

            if (count($selected) >= $limit) {
                break;
            }
        }

        $selected = array_reverse($selected);

        if ($currentInbound !== null && $this->isSemanticDialogue($currentInbound)) {
            $alreadyIncluded = false;

            foreach ($selected as $message) {
                if ((int) $message->id === (int) $currentInbound->id) {
                    $alreadyIncluded = true;
                    break;
                }
            }

            if (! $alreadyIncluded) {
                $selected[] = $currentInbound;
            }
        }

        $payload = [];

        foreach ($selected as $message) {
            $role = $message->role === MessageRole::Assistant ? 'assistant' : 'user';
            $body = trim((string) $message->body);

            if ($body === '') {
                continue;
            }

            $payload[] = new AiChatMessage($role, $body);
        }

        return $payload;
    }

    public function isSemanticDialogue(Message $message): bool
    {
        if ($message->role === MessageRole::System) {
            return false;
        }

        if ($message->message_type === MessageType::System) {
            return false;
        }

        $metadata = $message->metadata ?? [];

        if (($metadata['technical'] ?? false) === true) {
            return false;
        }

        $body = (string) $message->body;

        if (str_contains($body, 'Сообщение сохранено')) {
            return false;
        }

        return in_array($message->role, [MessageRole::User, MessageRole::Assistant], true);
    }

    private function isUnfinishedHistoricalInbound(Message $message, ?Message $currentInbound): bool
    {
        if ($message->role !== MessageRole::User) {
            return false;
        }

        if ($currentInbound !== null && (int) $message->id === (int) $currentInbound->id) {
            return false;
        }

        $status = $message->metadata['ai']['status'] ?? null;

        return in_array($status, ['pending', 'failed'], true);
    }

    private function recentLimit(AiRoleSetting $configuration): int
    {
        $parameters = $configuration->parameters ?? [];
        $limit = (int) ($parameters['recent_message_limit'] ?? self::DEFAULT_RECENT_LIMIT);

        return max(self::MIN_RECENT_LIMIT, min(self::MAX_RECENT_LIMIT, $limit));
    }
}
