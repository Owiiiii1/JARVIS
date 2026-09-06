<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateElevenLabsCustomLlm
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = trim((string) config('voice.realtime.custom_llm_secret', ''));
        $token = (string) $request->bearerToken();

        if ($secret === '' || $token === '' || strlen($secret) !== strlen($token) || ! hash_equals($secret, $token)) {
            return response()->json([
                'error' => [
                    'message' => 'Unauthorized',
                    'type' => 'unauthorized',
                ],
            ], 401);
        }

        if (! (bool) config('voice.realtime.enabled', false)) {
            return response()->json([
                'error' => [
                    'message' => 'Realtime conversation is not configured.',
                    'type' => 'voice_realtime_not_configured',
                ],
            ], 503);
        }

        return $next($request);
    }
}
