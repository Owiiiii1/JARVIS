<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Services\Reports\ScheduledReportException;
use App\Services\Reports\ScheduledReportService;
use App\Services\Users\UserCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JarvisScheduledReportController extends Controller
{
    public function __construct(
        private readonly ScheduledReportService $reports,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertReports($user);

        try {
            return response()->json($this->reports->panelFor($user));
        } catch (ScheduledReportException $exception) {
            return $this->error($exception);
        }
    }

    public function pause(Request $request, int $report): JsonResponse
    {
        return $this->mutate($request, $report, fn ($user, $id) => $this->reports->pauseOwned($user, $id));
    }

    public function resume(Request $request, int $report): JsonResponse
    {
        return $this->mutate($request, $report, fn ($user, $id) => $this->reports->resumeOwned($user, $id));
    }

    public function cancel(Request $request, int $report): JsonResponse
    {
        return $this->mutate($request, $report, fn ($user, $id) => $this->reports->cancelOwned($user, $id));
    }

    private function mutate(Request $request, int $report, callable $action): JsonResponse
    {
        $user = $request->user();
        $this->assertReports($user);

        try {
            $action($user, $report);

            return $this->panel($user);
        } catch (ScheduledReportException $exception) {
            return $this->error($exception);
        }
    }

    private function panel($user): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'active_count' => $this->reports->activeCount($user),
            ...$this->reports->panelFor($user),
        ]);
    }

    private function error(ScheduledReportException $exception): JsonResponse
    {
        $status = match ($exception->error) {
            'not_found' => 404,
            'capability_denied' => 403,
            default => 422,
        };

        return response()->json([
            'error' => $exception->error,
            'message' => $exception->getMessage(),
            'candidates' => $exception->candidates,
        ], $status);
    }

    private function assertReports($user): void
    {
        if ($user === null || ! $user->isActive() || ! $user->canUseCapability(UserCapability::SCHEDULED_REPORTS)) {
            abort(403);
        }
    }
}
