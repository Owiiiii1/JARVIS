<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Users\UserCapability;

final class CancelToolActionTool implements JarvisTool
{
    public const NAME = 'cancel_tool_action';

    public function __construct(
        private readonly ToolConfirmationService $confirmations,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Cancels the current pending tool confirmation after the user declined. Do not invent confirmation_id.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'confirmation_id' => [
                        'type' => 'STRING',
                        'description' => 'Optional pending confirmation public id.',
                    ],
                ],
                'required' => [],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::CHAT,
            operation: ToolOperationClass::Write,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $this->confirmations->hasPending($context->user, $context->conversation);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        if ($context->confirmationIntent !== ConfirmationIntentParser::CANCEL) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'confirmation_not_affirmed',
            ]);
        }

        $publicId = trim((string) ($call->arguments['confirmation_id'] ?? ''));
        $pending = $publicId !== ''
            ? $this->confirmations->findOwnedPending($context->user, $context->conversation, $publicId)
            : $this->confirmations->latestPending($context->user, $context->conversation);

        if ($pending === null) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'confirmation_not_found',
            ]);
        }

        $this->confirmations->cancel($pending);

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'cancelled' => true,
            'confirmation_id' => $pending->public_id,
        ]);
    }
}
