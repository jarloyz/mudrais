<?php

namespace App\Filament\Pages;

use App\Models\ContinuityCommit;
use App\Models\ContinuityStateChange;
use App\Models\ContinuityTurn;
use App\Support\WorkspaceContext;
use Filament\Pages\Page;

class TimelinePage extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Timeline';

    protected static string | \UnitEnum | null $navigationGroup = 'Roleplay';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'timeline';

    protected string $view = 'filament.pages.timeline-page';

    public string $sceneId = '';

    public string $continuityId = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $turns = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $commits = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $stateChanges = [];

    public function mount(): void
    {
        $defaults = WorkspaceContext::defaults();
        $this->sceneId = $defaults['scene_id'];
        $this->continuityId = $defaults['continuity_id'];
        $this->loadTimeline();
    }

    public function loadTimeline(): void
    {
        $sceneId = trim($this->sceneId);
        $continuityId = trim($this->continuityId);

        if ($sceneId === '') {
            $this->turns = [];
            $this->commits = [];
            $this->stateChanges = [];

            return;
        }

        WorkspaceContext::store([
            'scene_id' => $sceneId,
            'continuity_id' => $continuityId,
        ]);

        $turnsQuery = ContinuityTurn::query()
            ->where('activity_id', $sceneId)
            ->orderBy('turn_index');
        $commitsQuery = ContinuityCommit::query()
            ->where('activity_id', $sceneId)
            ->orderBy('turn_index')
            ->orderBy('id');
        $stateChangesQuery = ContinuityStateChange::query()
            ->where('activity_id', $sceneId)
            ->orderBy('id');

        if ($continuityId !== '') {
            $turnsQuery->where('continuity_id', $continuityId);
            $commitsQuery->where('continuity_id', $continuityId);
            $stateChangesQuery->where('continuity_id', $continuityId);
        }

        $this->turns = $turnsQuery->get()->map(static fn (ContinuityTurn $turn): array => [
            'id' => $turn->id,
            'continuity_id' => $turn->continuity_id,
            'turn_index' => $turn->turn_index,
            'mode' => $turn->mode,
            'user_message' => $turn->user_message,
            'output_md' => $turn->output_md,
        ])->all();

        $this->commits = $commitsQuery->get()->map(static fn (ContinuityCommit $commit): array => [
            'id' => $commit->id,
            'continuity_id' => $commit->continuity_id,
            'parent_commit_id' => $commit->parent_commit_id,
            'turn_index' => $commit->turn_index,
            'mode' => $commit->mode,
            'message' => $commit->message,
        ])->all();

        $this->stateChanges = $stateChangesQuery->get()->map(static fn (ContinuityStateChange $change): array => [
            'id' => $change->id,
            'continuity_id' => $change->continuity_id,
            'kind' => $change->kind,
            'scope_type' => $change->scope_type,
            'scope_id' => $change->scope_id,
            'change' => $change->change,
            'severity' => $change->severity,
        ])->all();
    }
}
