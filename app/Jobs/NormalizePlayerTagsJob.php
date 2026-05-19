<?php

namespace App\Jobs;

use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Jobs\IndexPlayerStyleJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrator: detach de todos los tags del perfil + dispatch de un NormalizeSingleTagJob por término.
 * El procesamiento real (LLM + embedding + Qdrant) ocurre en NormalizeSingleTagJob,
 * cuyo timeout de 60s es suficiente para un solo tag (~10-15s).
 */
class NormalizePlayerTagsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;
    public int $tries   = 3;

    /**
     * @param list<string> $redLines
     * @param list<string> $yellowLines
     * @param list<string> $affinities
     * @param ShouldQueue|null $continuation Job que se ejecuta al final de la cadena interna,
     *                                       después de IndexPlayerStyleJob. Útil para enviar
     *                                       mensajes de éxito solo cuando la indexación terminó.
     */
    public function __construct(
        public readonly PlayerArchetypeProfile $profile,
        public readonly array                  $redLines,
        public readonly array                  $yellowLines    = [],
        public readonly array                  $affinities     = [],
        public readonly array                  $rawRedLines    = [],
        public readonly array                  $rawYellowLines = [],
        public readonly array                  $rawAffinities  = [],
        public readonly string                 $semanticTagQuery = '',
        public readonly ?ShouldQueue           $continuation   = null,
    ) {
        $this->onQueue('tags');
    }

    public function handle(): void
    {
        $profileId = $this->profile->id;

        Log::info('NormalizePlayerTagsJob: iniciando.', [
            'profile_id'    => $profileId,
            'discord_id'    => $this->profile->discord_user_id,
            'archetype_id'  => $this->profile->archetype_id,
            'red_lines'     => count($this->redLines),
            'yellow_lines'  => count($this->yellowLines),
            'affinities'    => count($this->affinities),
            'semantic_terms'=> $this->semanticTagQuery !== ''
                ? count(array_filter(array_map('trim', explode(',', $this->semanticTagQuery))))
                : 0,
        ]);

        $this->profile->tags()->detach();

        $tagJobs = [];
        $tagJobs = array_merge($tagJobs, $this->buildContext($profileId, $this->redLines, $this->rawRedLines, 'red_line'));
        $tagJobs = array_merge($tagJobs, $this->buildContext($profileId, $this->yellowLines, $this->rawYellowLines, 'yellow_line'));
        $tagJobs = array_merge($tagJobs, $this->buildContext($profileId, $this->affinities, $this->rawAffinities, 'preference'));

        if ($this->semanticTagQuery !== '') {
            $concepts = array_values(array_filter(
                array_map('trim', explode(',', $this->semanticTagQuery))
            ));
            $tagJobs = array_merge($tagJobs, $this->buildContext($profileId, $concepts, [], 'semantic_match'));
        }

        $chain = [...$tagJobs, new IndexPlayerStyleJob($profileId)];

        if ($this->continuation !== null) {
            $chain[] = $this->continuation;
        }

        Bus::chain($chain)
            ->onQueue('tags')
            ->dispatch();

        Log::info('NormalizePlayerTagsJob: chain despachado.', [
            'profile_id'       => $profileId,
            'tag_jobs'         => count($tagJobs),
            'has_continuation' => $this->continuation !== null,
        ]);
    }

    /**
     * @param list<string> $items
     * @param list<string> $rawItems
     * @return list<NormalizeSingleTagJob>
     */
    private function buildContext(string $profileId, array $items, array $rawItems, string $context): array
    {
        return array_map(
            fn (int $i, string $term) => new NormalizeSingleTagJob(
                avatarId:     null,
                profileId:    $profileId,
                term:         $term,
                tagContext:   $context,
                originalText: $rawItems[$i] ?? $term,
                playerId:     $profileId,
                archetypeId:  $this->profile->archetype_id,
            ),
            array_keys($items),
            array_values($items),
        );
    }
}
