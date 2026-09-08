<?php

namespace App\Services\Tools\Reports;

use App\Models\ScheduledReport;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolResult;
use App\Services\ConversationIntelligence\ReferenceResolver;
use App\Services\Reports\ScheduledReportSelection;
use App\Services\Reports\ScheduledReportService;
use App\Services\Tools\ToolExecutionContext;

final class ScheduledReportToolResolver
{
    public function __construct(
        private readonly ScheduledReportService $reports,
        private readonly ReferenceResolver $references = new ReferenceResolver,
    ) {}

    /**
     * @return array{ok: true, report: ScheduledReport}|ToolResult
     */
    public function resolve(ToolCall $call, ToolExecutionContext $context, string $toolName): array|ToolResult
    {
        $id = isset($call->arguments['report_id']) ? (int) $call->arguments['report_id'] : null;
        $query = isset($call->arguments['query']) ? trim((string) $call->arguments['query']) : null;
        $pronominal = $this->references->isPronominalQuery($query);
        $inboundText = mb_strtolower(trim((string) ($context->inbound?->body ?? '')));
        $inboundPronominal = $inboundText !== '' && $this->references->hasDeictic($inboundText);

        if (($id === null || $id <= 0) && ($query === null || $query === '' || $pronominal || $inboundPronominal)) {
            $trusted = $context->working?->uniqueTrustedReport();

            if ($trusted !== null && $trusted->id !== null && $context->working->allowsTrustedMutation()) {
                $id = $trusted->id;
                $query = null;
            }
        }

        $selection = ScheduledReportSelection::resolve(
            ($id !== null && $id > 0) ? $id : null,
            $query,
            $this->reports->candidatesForMutation($context->user),
        );

        if ($selection['ok'] !== true) {
            return ToolResult::failure($call->id, $toolName, [
                'success' => false,
                'error' => $selection['error'],
                'message' => $selection['error'] === 'ambiguous'
                    ? 'Specify which report. Call list_scheduled_reports if several exist.'
                    : 'Report was not found.',
                'candidates' => ScheduledReportSelection::summarize($selection['candidates'] ?? []),
            ]);
        }

        return $selection;
    }
}
