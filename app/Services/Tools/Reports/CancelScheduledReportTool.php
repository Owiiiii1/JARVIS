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

final class CancelScheduledReportTool implements JarvisTool
{
    public const NAME = 'cancel_scheduled_report';

    public function __construct(
        private readonly ScheduledReportService $reports,
        private readonly ScheduledReportToolResolver $resolver,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Cancels an owned scheduled report. History remains.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'report_id' => ['type' => 'INTEGER'],
                    'query' => ['type' => 'STRING'],
                ],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::SCHEDULED_REPORTS, operation: ToolOperationClass::Write);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::SCHEDULED_REPORTS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $resolved = $this->resolver->resolve($call, $context, $this->name());
        if ($resolved instanceof ToolResult) {
            return $resolved;
        }

        try {
            $report = $this->reports->cancelOwned($context->user, (int) $resolved['report']->id);
        } catch (ScheduledReportException $exception) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => $exception->error]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'report_id' => (int) $report->id,
            'status' => $report->status->value,
        ]);
    }
}
