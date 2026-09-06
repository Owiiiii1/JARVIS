<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Voice\ElevenLabsRealtimeSessionService;
use App\Services\Voice\ElevenLabsRealtimeTurnAdapter;
use App\Services\Voice\Exceptions\VoiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ElevenLabsCustomLlmController extends Controller
{
    public function __construct(
        private readonly ElevenLabsRealtimeSessionService $sessions,
        private readonly ElevenLabsRealtimeTurnAdapter $adapter,
    ) {}

    public function completions(Request $request): StreamedResponse|JsonResponse
    {
        $payload = $request->all();
        $token = $this->adapter->sessionTokenFromPayload($payload);

        if ($token === '') {
            return response()->json([
                'error' => [
                    'message' => 'Unauthorized',
                    'type' => 'unauthorized',
                ],
            ], 401);
        }

        try {
            $session = $this->sessions->resolveAdapterSession($token);
            $result = $this->adapter->complete($session, $payload);
        } catch (VoiceException $exception) {
            $status = $exception->httpStatus >= 400 ? $exception->httpStatus : 401;

            return response()->json([
                'error' => [
                    'message' => 'Unauthorized',
                    'type' => $exception->error,
                ],
            ], $status === 401 || $status === 403 || $status === 404 ? 401 : $status);
        } catch (Throwable) {
            return response()->json([
                'error' => [
                    'message' => 'Voice runtime failed.',
                    'type' => 'voice_runtime_failed',
                ],
            ], 500);
        }

        $completionId = $result['id'];
        $text = $result['text'];

        return response()->stream(function () use ($completionId, $text): void {
            $this->adapter->streamSse($completionId, $text, function (array|string $chunk): void {
                if (is_string($chunk)) {
                    echo 'data: '.$chunk."\n\n";
                } else {
                    echo 'data: '.json_encode($chunk, JSON_UNESCAPED_UNICODE)."\n\n";
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            });
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
