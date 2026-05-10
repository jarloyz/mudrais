<?php

namespace App\Filament\Pages;

use App\Application\UseCases\CheckoutContinuityCommitUseCase;
use App\Application\UseCases\CreateContinuityBranchFromCommitUseCase;
use App\Application\UseCases\CreateContinuityBranchFromTurnUseCase;
use App\Application\UseCases\CreateContinuityBranchUseCase;
use App\Application\UseCases\GenerateContinuityTurnUseCase;
use App\Application\UseCases\RewindContinuityToTurnUseCase;
use App\Application\UseCases\SwitchSceneBranchUseCase;
use App\Support\WorkspaceContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ContinuityPage extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'Continuidad';

    protected static string | \UnitEnum | null $navigationGroup = 'Roleplay';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'continuity';

    protected string $view = 'filament.pages.continuity-page';

    public string $sceneId = '';
    public string $continuityId = '';
    public string $userId = '';
    public string $mode = 'write_scene';
    public bool $apply = true;
    public string $userMessage = '';
    public string $switchContinuityId = '';
    public string $checkoutCommitId = '';
    public string $rewindTurnIndex = '';
    public string $branchNewContinuityId = '';
    public string $branchLabel = '';
    public string $branchFromTurnNewContinuityId = '';
    public string $branchFromTurnTurnIndex = '';
    public string $branchFromTurnLabel = '';
    public string $branchFromCommitNewContinuityId = '';
    public string $branchFromCommitCommitId = '';
    public string $branchFromCommitLabel = '';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $lastResult = null;

    public function mount(): void
    {
        $defaults = WorkspaceContext::defaults();
        $this->sceneId = $defaults['scene_id'];
        $this->continuityId = $defaults['continuity_id'];
        $this->userId = Auth::id() ? (string) Auth::id() : $defaults['user_id'];
        $this->mode = $defaults['mode'];
        $this->apply = $defaults['apply'];
    }

    public function generateTurn(): void
    {
        $this->runSafely(function (): array {
            return app(GenerateContinuityTurnUseCase::class)->execute(
                continuityId: trim($this->continuityId),
                sceneId: trim($this->sceneId),
                userMessage: trim($this->userMessage),
                mode: $this->mode,
                apply: $this->apply,
                userId: $this->normalizeUserId(),
            );
        }, 'Turno de continuidad generado');
    }

    public function createBranch(): void
    {
        $this->runSafely(function (): array {
            return app(CreateContinuityBranchUseCase::class)->execute(
                parentContinuityId: trim($this->continuityId),
                newContinuityId: trim($this->branchNewContinuityId),
                label: $this->normalizeNullable(trim($this->branchLabel)),
            );
        }, 'Rama creada');
    }

    public function createBranchFromTurn(): void
    {
        $this->runSafely(function (): array {
            return app(CreateContinuityBranchFromTurnUseCase::class)->execute(
                parentContinuityId: trim($this->continuityId),
                newContinuityId: trim($this->branchFromTurnNewContinuityId),
                sceneId: trim($this->sceneId),
                turnIndex: (int) $this->branchFromTurnTurnIndex,
                label: $this->normalizeNullable(trim($this->branchFromTurnLabel)),
            );
        }, 'Rama creada desde turno');
    }

    public function createBranchFromCommit(): void
    {
        $this->runSafely(function (): array {
            return app(CreateContinuityBranchFromCommitUseCase::class)->execute(
                parentContinuityId: trim($this->continuityId),
                newContinuityId: trim($this->branchFromCommitNewContinuityId),
                sceneId: trim($this->sceneId),
                commitId: (int) $this->branchFromCommitCommitId,
                label: $this->normalizeNullable(trim($this->branchFromCommitLabel)),
            );
        }, 'Rama creada desde commit');
    }

    public function checkoutCommit(): void
    {
        $this->runSafely(function (): array {
            return app(CheckoutContinuityCommitUseCase::class)->execute(
                continuityId: trim($this->continuityId),
                sceneId: trim($this->sceneId),
                commitId: (int) $this->checkoutCommitId,
            );
        }, 'Commit restaurado');
    }

    public function rewindTurn(): void
    {
        $this->runSafely(function (): array {
            return app(RewindContinuityToTurnUseCase::class)->execute(
                continuityId: trim($this->continuityId),
                sceneId: trim($this->sceneId),
                turnIndex: (int) $this->rewindTurnIndex,
            );
        }, 'Turno restaurado');
    }

    public function switchBranch(): void
    {
        $this->runSafely(function (): array {
            return app(SwitchSceneBranchUseCase::class)->execute(
                sceneId: trim($this->sceneId),
                continuityId: trim($this->switchContinuityId),
            );
        }, 'Rama activa actualizada');

        $this->continuityId = trim($this->switchContinuityId);
    }

    /**
     * @param callable():array<string, mixed> $callback
     */
    private function runSafely(callable $callback, string $successTitle): void
    {
        try {
            WorkspaceContext::store([
                'scene_id' => trim($this->sceneId),
                'continuity_id' => trim($this->continuityId),
                'user_id' => (string) ($this->normalizeUserId() ?? ''),
                'mode' => $this->mode,
                'apply' => $this->apply,
            ]);

            $this->lastResult = $callback();

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Operacion fallida')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    private function normalizeUserId(): ?string
    {
        if (Auth::id()) {
            return (string) Auth::id();
        }

        $candidate = trim($this->userId);

        if ($candidate === '') {
            return null;
        }

        return $candidate;
    }

    private function normalizeNullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
