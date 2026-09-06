<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Services\Workspace\WorkspaceSurfaceStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JarvisWorkspaceStatusController extends Controller
{
    public function __construct(
        private readonly WorkspaceSurfaceStateService $state,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->state->status($request->user()));
    }
}
