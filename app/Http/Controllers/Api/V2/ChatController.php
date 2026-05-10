<?php

namespace App\Http\Controllers\Api\V2;

use App\Application\Contracts\StructuredLogger;
use App\Application\UseCases\GenerateContinuityTurnUseCase;
use App\Application\UseCases\GenerateSceneTurnUseCase;
use App\Application\UseCases\RefreshSimpleChatMemoryUseCase;
use App\Http\Controllers\Controller;
use App\Infrastructure\Ai\Agents\ContentSafetyAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ChatController extends Controller
{
    public function store(
        Request $request,
        StructuredLogger $logger,
    ): JsonResponse {
        $payload = $this->validatePayload($request);

        try {
            $result = $this->executeChat($payload);
            $this->scheduleSimpleMemoryRefresh($payload, $result, $logger);

            return response()->json($result);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->errorResponse($logger, $exception, 422);
        } catch (Throwable $exception) {
            return $this->errorResponse($logger, $exception, 500);
        }
    }

    public function stream(
        Request $request,
        StructuredLogger $logger,
    ): StreamedResponse|JsonResponse {
        $payload = $this->validatePayload($request);

        try {
            $streamLogger = $logger->withContext([
                'layer' => 'http',
                'endpoint' => 'api.v2.chat.stream',
                'sceneId' => $payload['scene_id'],
                'continuityId' => $payload['continuity_id'] ?? null,
            ]);

            $streamLogger->info('Inicio de stream de chat API v2');

            return response()->stream(function () use ($payload, $streamLogger): void {
                try {
                    $this->emitSseEvent('meta', [
                        'sceneId' => $payload['scene_id'],
                        'continuityId' => $payload['continuity_id'] ?? null,
                        'mode' => (string) ($payload['mode'] ?? 'write_scene'),
                    ]);

                    $chunkIndex = 0;
                    $result = $this->executeChat(
                        $payload,
                        function (string $delta) use (&$chunkIndex): void {
                            if ($delta === '') {
                                return;
                            }

                            $this->emitSseEvent('chunk', [
                                'index' => $chunkIndex++,
                                'delta' => $delta,
                            ]);
                        },
                        function (string $event, mixed $data): void {
                            $this->emitSseEvent($event, $data);
                        }
                    );

                    $this->scheduleSimpleMemoryRefresh($payload, $result, $streamLogger);
                    $this->emitSseEvent('done', $result);
                    $streamLogger->info('Stream de chat API v2 completado', [
                        'outputChars' => mb_strlen((string) ($result['outputMd'] ?? '')),
                    ]);
                } catch (Throwable $exception) {
                    $streamLogger->error('Stream de chat API v2 fallo', [
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);

                    $this->emitSseEvent('error', [
                        'error' => $exception->getMessage(),
                    ]);
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
            ]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->errorResponse($logger, $exception, 422);
        } catch (Throwable $exception) {
            return $this->errorResponse($logger, $exception, 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'scene_id' => ['required', 'string'],
            'user_message' => ['required', 'string'],
            'mode' => ['nullable', 'string'],
            'apply' => ['nullable', 'boolean'],
            'continuity_id' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer'],
            'qa_loop_enabled' => ['nullable', 'boolean'],
            'qa_loop_max_passes' => ['nullable', 'integer', 'min:1', 'max:3'],
            'qa_loop_min_severity' => ['nullable', 'string', 'in:minor,medium,major'],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function executeChat(array $payload, ?callable $onChunk = null, ?callable $onProgress = null): array
    {
        $userMessage = $payload['user_message'] ?? '';
        $isSafe = app(ContentSafetyAgent::class)->checkChat($userMessage);

        if (!$isSafe) {
            throw new InvalidArgumentException(
                'Tu mensaje no puede ser procesado porque infringe las políticas de contenido.'
            );
        }

        /** @var GenerateSceneTurnUseCase $generateSceneTurnUseCase */
        $generateSceneTurnUseCase = app(GenerateSceneTurnUseCase::class);
        /** @var GenerateContinuityTurnUseCase $generateContinuityTurnUseCase */
        $generateContinuityTurnUseCase = app(GenerateContinuityTurnUseCase::class);

        if (isset($payload['continuity_id']) && trim((string) $payload['continuity_id']) !== '') {
            return $generateContinuityTurnUseCase->execute(
                continuityId: $payload['continuity_id'],
                sceneId: $payload['scene_id'],
                userMessage: $payload['user_message'],
                mode: (string) ($payload['mode'] ?? 'write_scene'),
                apply: (bool) ($payload['apply'] ?? true),
                userId: isset($payload['user_id']) ? (int) $payload['user_id'] : null,
                onChunk: $onChunk,
                qaLoop: [
                    'enabled' => (bool) ($payload['qa_loop_enabled'] ?? false),
                    'max_passes' => (int) ($payload['qa_loop_max_passes'] ?? 1),
                    'min_severity' => (string) ($payload['qa_loop_min_severity'] ?? 'medium'),
                ],
                onProgress: $onProgress,
            );
        }

        return $generateSceneTurnUseCase->execute(
            sceneId: $payload['scene_id'],
            userMessage: $payload['user_message'],
            mode: (string) ($payload['mode'] ?? 'write_scene'),
            apply: (bool) ($payload['apply'] ?? true),
            userId: isset($payload['user_id']) ? (int) $payload['user_id'] : null,
            onChunk: $onChunk,
            qaLoop: [
                'enabled' => (bool) ($payload['qa_loop_enabled'] ?? false),
                'max_passes' => (int) ($payload['qa_loop_max_passes'] ?? 1),
                'min_severity' => (string) ($payload['qa_loop_min_severity'] ?? 'medium'),
            ],
            onProgress: $onProgress,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $result
     */
    private function scheduleSimpleMemoryRefresh(array $payload, array $result, StructuredLogger $logger): void
    {
        if (($result['sceneType'] ?? null) !== 'simple') {
            return;
        }

        if (($result['applied'] ?? true) !== true) {
            return;
        }

        if (isset($payload['continuity_id']) && trim((string) $payload['continuity_id']) !== '') {
            return;
        }

        $sceneId = (string) ($result['sceneId'] ?? $payload['scene_id'] ?? '');
        $userMessage = trim((string) ($payload['user_message'] ?? ''));
        $outputMd = trim((string) ($result['outputMd'] ?? ''));
        $userId = isset($payload['user_id']) && is_numeric($payload['user_id'])
            ? (int) $payload['user_id']
            : auth()->id();

        if ($sceneId === '' || $userMessage === '' || $outputMd === '') {
            return;
        }

        app()->terminating(function () use ($sceneId, $userMessage, $outputMd, $userId, $logger): void {
            try {
                app(RefreshSimpleChatMemoryUseCase::class)->execute(
                    sceneId: $sceneId,
                    userMessage: $userMessage,
                    outputMd: $outputMd,
                    userId: $userId,
                );
            } catch (Throwable $exception) {
                $logger->withContext([
                    'layer' => 'http',
                    'sceneId' => $sceneId,
                    'post_response' => 'simple_memory_refresh',
                ])->error('Refresh de memoria simple post-respuesta fallo', [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        });
    }

    /**
     * @param mixed $payload
     */
    private function emitSseEvent(string $event, mixed $payload): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

        if (function_exists('ob_flush')) {
            @ob_flush();
        }

        flush();
    }

    private function errorResponse(
        StructuredLogger $logger,
        Throwable $exception,
        int $status,
    ): JsonResponse {
        $logger
            ->withContext([
                'layer' => 'http',
                'endpoint' => 'api.v2.chat',
                'status' => $status,
            ])
            ->error('Solicitud chat API v2 fallo', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

        return response()->json([
            'error' => $exception->getMessage(),
        ], $status);
    }
}
