<?php

namespace App\Jobs;

use App\Domains\Narrative\Models\Avatar;
use App\Jobs\FinalizeAvatarIndexJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrator: detach de tags semánticos previos + dispatch de un NormalizeSingleTagJob por término.
 * Los tags se procesan en paralelo (Bus::batch); FinalizeAvatarIndexJob corre en el callback then().
 */
class NormalizeAvatarTagsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;
    public int $tries   = 3;

    /**
     * @param list<string> $rawTerms  Términos extraídos del semantic_tag_query
     */
    public function __construct(
        public readonly string  $avatarId,
        public readonly array   $rawTerms,
        public readonly ?string $playerId = null,
    ) {
        $this->onQueue('tags');
    }

    public function handle(): void
    {
        Log::info('NormalizeAvatarTagsJob: iniciando.', [
            'avatar_id'   => $this->avatarId,
            'player_id'   => $this->playerId,
            'total_terms' => count($this->rawTerms),
        ]);

        $avatar = Avatar::find($this->avatarId);

        if (! $avatar) {
            Log::warning('NormalizeAvatarTagsJob: avatar no encontrado.', ['avatar_id' => $this->avatarId]);
            return;
        }

        $avatar->tags()->wherePivot('tag_context', 'semantic')->detach();

        $tagJobs = array_map(
            fn (string $term) => new NormalizeSingleTagJob(
                avatarId:     $this->avatarId,
                profileId:    null,
                term:         $term,
                tagContext:   'semantic',
                originalText: $term,
                playerId:     $this->playerId,
            ),
            $this->rawTerms,
        );

        $avatarId = $this->avatarId;

        // Los tags corren en paralelo; FinalizeAvatarIndexJob corre cuando todos terminan.
        // allowFailures() evita que un tag fallido cancele el batch entero.
        Bus::batch($tagJobs)
            ->then(fn () => FinalizeAvatarIndexJob::dispatch($avatarId))
            ->allowFailures()
            ->onQueue('tags')
            ->dispatch();

        Log::info('NormalizeAvatarTagsJob: batch despachado.', [
            'avatar_id'  => $this->avatarId,
            'tag_jobs'   => count($tagJobs),
        ]);
    }
}
