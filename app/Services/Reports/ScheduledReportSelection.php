<?php

namespace App\Services\Reports;

use App\Models\ScheduledReport;

final class ScheduledReportSelection
{
    /**
     * @param  list<ScheduledReport>  $candidates
     * @return array{ok: true, report: ScheduledReport}|array{ok: false, error: string, candidates?: list<ScheduledReport>}
     */
    public static function resolve(?int $id, ?string $query, array $candidates): array
    {
        if ($id !== null && $id > 0) {
            foreach ($candidates as $candidate) {
                if ((int) $candidate->id === $id) {
                    return ['ok' => true, 'report' => $candidate];
                }
            }

            return ['ok' => false, 'error' => 'not_found'];
        }

        $needle = mb_strtolower(trim((string) $query));

        if ($needle === '') {
            if (count($candidates) === 1) {
                return ['ok' => true, 'report' => $candidates[0]];
            }

            return [
                'ok' => false,
                'error' => count($candidates) === 0 ? 'not_found' : 'ambiguous',
                'candidates' => $candidates,
            ];
        }

        $matched = [];

        foreach ($candidates as $candidate) {
            $haystack = mb_strtolower(trim((string) $candidate->name).' '.$candidate->report_type->value);

            if (str_contains($haystack, $needle)) {
                $matched[] = $candidate;
            }
        }

        if (count($matched) === 1) {
            return ['ok' => true, 'report' => $matched[0]];
        }

        if ($matched === []) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        return ['ok' => false, 'error' => 'ambiguous', 'candidates' => $matched];
    }

    /**
     * @param  list<ScheduledReport>  $reports
     * @return list<array{id: int, name: string, status: string, report_type: string, local_time: string}>
     */
    public static function summarize(array $reports): array
    {
        return array_map(static function (ScheduledReport $report): array {
            return [
                'id' => (int) $report->id,
                'name' => (string) $report->name,
                'status' => $report->status->value,
                'report_type' => $report->report_type->value,
                'local_time' => (string) $report->local_time,
            ];
        }, $reports);
    }
}
