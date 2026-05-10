<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContinuityCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_continuity_turn_command_outputs_summary(): void
    {
        $this->app->instance(\App\Application\UseCases\GenerateContinuityTurnUseCase::class, new class
        {
            public function execute(
                string $continuityId,
                string $sceneId,
                string $userMessage,
                string $mode = 'write_scene',
                bool $apply = true,
                ?string $userId = null,
            ): array {
                if ($continuityId !== 'cont_demo' || $sceneId !== 'scene_demo' || $userMessage !== 'mensaje' || $mode !== 'write_scene' || $apply !== true || $userId !== 9) {
                    throw new \RuntimeException('Argumentos inesperados');
                }

                return [
                    'continuityId' => 'cont_demo',
                    'sceneId' => 'scene_demo',
                    'turnIndex' => 4,
                    'commitId' => 18,
                    'sceneType' => 'simple',
                    'applied' => true,
                    'outputMd' => 'Salida continuidad',
                ];
            }
        });

        $this->artisan('historia:continuity-turn', [
            'continuityId' => 'cont_demo',
            'sceneId' => 'scene_demo',
            'message' => 'mensaje',
            '--user-id' => '9',
        ])
            ->expectsOutputToContain('Turno de continuidad generado')
            ->expectsOutputToContain('Salida continuidad')
            ->assertExitCode(0);
    }

    public function test_continuity_branch_command_supports_commit_origin(): void
    {
        $this->app->instance(\App\Application\UseCases\CreateContinuityBranchUseCase::class, new class
        {
            public function execute(string $parentContinuityId, string $newContinuityId, ?string $label = null): array
            {
                throw new \RuntimeException('No deberia llamar execute base');
            }
        });

        $this->app->instance(\App\Application\UseCases\CreateContinuityBranchFromCommitUseCase::class, new class
        {
            public function execute(string $parentContinuityId, string $newContinuityId, string $sceneId, int $commitId, ?string $label = null): array
            {
                if ($parentContinuityId !== 'cont_root' || $newContinuityId !== 'cont_branch' || $sceneId !== 'scene_demo' || $commitId !== 22 || $label !== 'rama commit') {
                    throw new \RuntimeException('Argumentos inesperados');
                }

                return [
                    'continuityId' => 'cont_branch',
                    'parentContinuityId' => 'cont_root',
                    'sceneId' => 'scene_demo',
                    'sourceCommitId' => 22,
                    'status' => 'active',
                ];
            }
        });

        $this->app->instance(\App\Application\UseCases\CreateContinuityBranchFromTurnUseCase::class, new class
        {
            public function execute(string $parentContinuityId, string $newContinuityId, string $sceneId, int $turnIndex, ?string $label = null): array
            {
                throw new \RuntimeException('No deberia llamar execute from turn');
            }
        });

        $this->artisan('historia:continuity-branch', [
            'parentContinuityId' => 'cont_root',
            'newContinuityId' => 'cont_branch',
            '--scene-id' => 'scene_demo',
            '--commit-id' => '22',
            '--label' => 'rama commit',
        ])
            ->expectsOutputToContain('Branch de continuidad creado')
            ->assertExitCode(0);
    }

    public function test_continuity_checkout_command_outputs_restored_commit(): void
    {
        $this->app->instance(\App\Application\UseCases\CheckoutContinuityCommitUseCase::class, new class
        {
            public function execute(string $continuityId, string $sceneId, int $commitId): array
            {
                if ($continuityId !== 'cont_demo' || $sceneId !== 'scene_demo' || $commitId !== 14) {
                    throw new \RuntimeException('Argumentos inesperados');
                }

                return [
                    'continuityId' => 'cont_demo',
                    'sceneId' => 'scene_demo',
                    'commitId' => 14,
                    'restored' => true,
                ];
            }
        });

        $this->artisan('historia:continuity-checkout', [
            'continuityId' => 'cont_demo',
            'sceneId' => 'scene_demo',
            'commitId' => '14',
        ])
            ->expectsOutputToContain('Checkout de continuidad completado')
            ->assertExitCode(0);
    }

    public function test_continuity_rewind_command_outputs_restored_turn(): void
    {
        $this->app->instance(\App\Application\UseCases\RewindContinuityToTurnUseCase::class, new class
        {
            public function execute(string $continuityId, string $sceneId, int $turnIndex): array
            {
                if ($continuityId !== 'cont_demo' || $sceneId !== 'scene_demo' || $turnIndex !== 3) {
                    throw new \RuntimeException('Argumentos inesperados');
                }

                return [
                    'continuityId' => 'cont_demo',
                    'sceneId' => 'scene_demo',
                    'turnIndex' => 3,
                    'commitId' => 11,
                    'restored' => true,
                ];
            }
        });

        $this->artisan('historia:continuity-rewind', [
            'continuityId' => 'cont_demo',
            'sceneId' => 'scene_demo',
            'turnIndex' => '3',
        ])
            ->expectsOutputToContain('Rewind de continuidad completado')
            ->assertExitCode(0);
    }
}
