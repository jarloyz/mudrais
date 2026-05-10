<?php

namespace App\Http\Controllers\Api\V2;

use App\Application\Contracts\StructuredLogger;
use App\Application\UseCases\CheckoutContinuityCommitUseCase;
use App\Application\UseCases\CreateContinuityBranchFromCommitUseCase;
use App\Application\UseCases\CreateContinuityBranchFromTurnUseCase;
use App\Application\UseCases\CreateContinuityBranchUseCase;
use App\Application\UseCases\GenerateContinuityTurnUseCase;
use App\Application\UseCases\RewindContinuityToTurnUseCase;
use App\Application\UseCases\SwitchSceneBranchUseCase;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ContinuityController extends Controller
{
    public function turn(
        Request $request,
        StructuredLogger $logger,
    ): JsonResponse {
        $payload = $request->validate([
            'continuity_id' => ['required', 'string'],
            'scene_id' => ['required', 'string'],
            'user_message' => ['required', 'string'],
            'mode' => ['nullable', 'string'],
            'apply' => ['nullable', 'boolean'],
            'user_id' => ['nullable', 'integer'],
        ]);

        try {
            /** @var GenerateContinuityTurnUseCase $useCase */
            $useCase = app(GenerateContinuityTurnUseCase::class);
            return response()->json($useCase->execute(
                continuityId: $payload['continuity_id'],
                sceneId: $payload['scene_id'],
                userMessage: $payload['user_message'],
                mode: (string) ($payload['mode'] ?? 'write_scene'),
                apply: (bool) ($payload['apply'] ?? true),
                userId: isset($payload['user_id']) ? (int) $payload['user_id'] : null,
            ));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->errorResponse($logger, $exception, 422, 'api.v2.continuity.turn');
        } catch (Throwable $exception) {
            return $this->errorResponse($logger, $exception, 500, 'api.v2.continuity.turn');
        }
    }

    public function branch(
        Request $request,
        StructuredLogger $logger,
    ): JsonResponse {
        $payload = $request->validate([
            'parent_continuity_id' => ['required', 'string'],
            'new_continuity_id' => ['required', 'string'],
            'label' => ['nullable', 'string'],
            'scene_id' => ['nullable', 'string'],
            'commit_id' => ['nullable', 'integer'],
            'turn_index' => ['nullable', 'integer'],
        ]);

        try {
            /** @var CreateContinuityBranchUseCase $createContinuityBranchUseCase */
            $createContinuityBranchUseCase = app(CreateContinuityBranchUseCase::class);
            /** @var CreateContinuityBranchFromCommitUseCase $createContinuityBranchFromCommitUseCase */
            $createContinuityBranchFromCommitUseCase = app(CreateContinuityBranchFromCommitUseCase::class);
            /** @var CreateContinuityBranchFromTurnUseCase $createContinuityBranchFromTurnUseCase */
            $createContinuityBranchFromTurnUseCase = app(CreateContinuityBranchFromTurnUseCase::class);

            if (isset($payload['commit_id'])) {
                if (! isset($payload['scene_id'])) {
                    throw new InvalidArgumentException('scene_id es requerido cuando se envia commit_id');
                }

                return response()->json($createContinuityBranchFromCommitUseCase->execute(
                    parentContinuityId: $payload['parent_continuity_id'],
                    newContinuityId: $payload['new_continuity_id'],
                    sceneId: $payload['scene_id'],
                    commitId: (int) $payload['commit_id'],
                    label: $payload['label'] ?? null,
                ));
            }

            if (isset($payload['turn_index'])) {
                if (! isset($payload['scene_id'])) {
                    throw new InvalidArgumentException('scene_id es requerido cuando se envia turn_index');
                }

                return response()->json($createContinuityBranchFromTurnUseCase->execute(
                    parentContinuityId: $payload['parent_continuity_id'],
                    newContinuityId: $payload['new_continuity_id'],
                    sceneId: $payload['scene_id'],
                    turnIndex: (int) $payload['turn_index'],
                    label: $payload['label'] ?? null,
                ));
            }

            return response()->json($createContinuityBranchUseCase->execute(
                parentContinuityId: $payload['parent_continuity_id'],
                newContinuityId: $payload['new_continuity_id'],
                label: $payload['label'] ?? null,
            ));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->errorResponse($logger, $exception, 422, 'api.v2.continuity.branch');
        } catch (Throwable $exception) {
            return $this->errorResponse($logger, $exception, 500, 'api.v2.continuity.branch');
        }
    }

    public function checkout(
        Request $request,
        StructuredLogger $logger,
    ): JsonResponse {
        $payload = $request->validate([
            'continuity_id' => ['required', 'string'],
            'scene_id' => ['required', 'string'],
            'commit_id' => ['required', 'integer'],
        ]);

        try {
            /** @var CheckoutContinuityCommitUseCase $useCase */
            $useCase = app(CheckoutContinuityCommitUseCase::class);
            return response()->json($useCase->execute(
                continuityId: $payload['continuity_id'],
                sceneId: $payload['scene_id'],
                commitId: (int) $payload['commit_id'],
            ));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->errorResponse($logger, $exception, 422, 'api.v2.continuity.checkout');
        } catch (Throwable $exception) {
            return $this->errorResponse($logger, $exception, 500, 'api.v2.continuity.checkout');
        }
    }

    public function rewind(
        Request $request,
        StructuredLogger $logger,
    ): JsonResponse {
        $payload = $request->validate([
            'continuity_id' => ['required', 'string'],
            'scene_id' => ['required', 'string'],
            'turn_index' => ['required', 'integer'],
        ]);

        try {
            /** @var RewindContinuityToTurnUseCase $useCase */
            $useCase = app(RewindContinuityToTurnUseCase::class);
            return response()->json($useCase->execute(
                continuityId: $payload['continuity_id'],
                sceneId: $payload['scene_id'],
                turnIndex: (int) $payload['turn_index'],
            ));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->errorResponse($logger, $exception, 422, 'api.v2.continuity.rewind');
        } catch (Throwable $exception) {
            return $this->errorResponse($logger, $exception, 500, 'api.v2.continuity.rewind');
        }
    }

    public function switch(
        Request $request,
        StructuredLogger $logger,
    ): JsonResponse {
        $payload = $request->validate([
            'scene_id' => ['required', 'string'],
            'continuity_id' => ['required', 'string'],
        ]);

        try {
            /** @var SwitchSceneBranchUseCase $useCase */
            $useCase = app(SwitchSceneBranchUseCase::class);
            return response()->json($useCase->execute(
                sceneId: $payload['scene_id'],
                continuityId: $payload['continuity_id'],
            ));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->errorResponse($logger, $exception, 422, 'api.v2.continuity.switch');
        } catch (Throwable $exception) {
            return $this->errorResponse($logger, $exception, 500, 'api.v2.continuity.switch');
        }
    }

    private function errorResponse(
        StructuredLogger $logger,
        Throwable $exception,
        int $status,
        string $endpoint,
    ): JsonResponse {
        $logger
            ->withContext([
                'layer' => 'http',
                'endpoint' => $endpoint,
                'status' => $status,
            ])
            ->error('Solicitud continuity API v2 fallo', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

        return response()->json([
            'error' => $exception->getMessage(),
        ], $status);
    }
}
