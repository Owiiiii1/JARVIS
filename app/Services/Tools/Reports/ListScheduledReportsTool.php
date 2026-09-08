<?php

namespace App\Services\Tools\Reports;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Reports\ScheduledReportException;
use App\Services\Reports\ScheduledReportService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class ListScheduledReportsTool implements JarvisTool
{
    public const NAME = 'list_scheduled_reports';

    public function __construct(
        private readonly ScheduledReportService $reports,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists the current user’s scheduled reports. Optional status: active, paused, cancelled.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'status' => ['type' => 'STRING'],
                ],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::SCHEDULED_REPORTS, operation: ToolOperationClass::Read);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::SCHEDULED_REPORTS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        try {
            $items = $this->reports->listFor(
                $context->user,
                isset($call->arguments['status']) ? (string) $call->arguments['status'] : null,
            )->map(fn ($report): array => $this->reports->serialize($report))->values()->all();
        } catch (ScheduledReportException $exception) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => $exception->error]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'count' => count($items),
            'reports' => $items,
        ]);
    }
}
