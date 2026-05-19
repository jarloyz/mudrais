<?php

namespace App\Domains\Matchmaking\Observers;

use App\Domains\Matchmaking\Models\ArchetypeMutator;
use App\Infrastructure\Ai\Agents\InterviewerAgent;
use App\Jobs\Discord\GenerateInterviewOpeningJob;
use Illuminate\Support\Facades\Log;

class ArchetypeMutatorObserver
{
    public function created(ArchetypeMutator $mutator): void
    {
        $this->dispatchIfAiField($mutator, 'created');
    }

    public function updated(ArchetypeMutator $mutator): void
    {
        $this->dispatchIfAiField($mutator, 'updated');
    }

    public function deleted(ArchetypeMutator $mutator): void
    {
        $this->dispatchIfAiField($mutator, 'deleted');
    }

    private function dispatchIfAiField(ArchetypeMutator $mutator, string $event): void
    {
        if ($mutator->context !== 'registration') {
            return;
        }

        if (! in_array($mutator->field_type, InterviewerAgent::AI_FIELD_TYPES, true)) {
            return;
        }

        Log::debug('[ArchetypeMutatorObserver] Mutador AI de registro modificado — generando apertura', [
            'archetype_id' => $mutator->archetype_id,
            'field_key'    => $mutator->field_key,
            'event'        => $event,
        ]);

        GenerateInterviewOpeningJob::dispatch($mutator->archetype_id);
    }
}
