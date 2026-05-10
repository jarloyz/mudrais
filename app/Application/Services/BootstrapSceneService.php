<?php

namespace App\Application\Services;

use App\Application\Contracts\ContinuityRepository;
use App\Application\Contracts\AgentGateway;
use App\Application\Contracts\SceneContextBuilder;
use App\Application\Contracts\SceneRepository;
use App\Domain\Scene\Scene as DomainScene;
use App\Infrastructure\Persistence\Eloquent\Models\CharacterRecord;
use App\Infrastructure\Persistence\Eloquent\Models\SceneRecord;
use App\Models\AiPromptTemplate;

class BootstrapSceneService
{
    public function __construct(
        private readonly SceneContextBuilder $sceneContextBuilder,
        private readonly AgentGateway $agentGateway,
        private readonly SceneRepository $sceneRepository,
        private readonly ContinuityRepository $continuityRepository
    ) {}

    public function generateOpening(SceneRecord $scene, ?CharacterRecord $character = null, ?string $userId = null, ?callable $onChunk = null): array
    {
        $location = $scene->location;
        $premise = $location ? ($location->entry_premise ?? '') : '';

        if ($character) {
            $systemInstruction = str_replace(
                ['{character_name}', '{premise}', '{facade}'],
                [$character->name, $premise, $character->public_facade ?? ''],
                AiPromptTemplate::getBodyOrFail('bootstrap_scene_with_player'),
            );
        } else {
            $systemInstruction = str_replace(
                '{premise}',
                $premise,
                AiPromptTemplate::getBodyOrFail('bootstrap_scene_general'),
            );
        }

        $activeCont = \App\Models\SceneActiveContinuity::query()->where('activity_id', $scene->id)->first();
        $continuityId = $activeCont ? $activeCont->continuity_id : null;

        $context = $this->sceneContextBuilder->build($scene->id, $continuityId, $userId, $character?->id);

        $domainScene = $this->sceneRepository->findById($scene->id);
        if (!$domainScene) {
            return [];
        }

        // Llamar directamente al Writer (AgentGateway) saltando Gatekeeper y Librarian
        $writerOutput = $this->agentGateway->generateSceneTurn(
            $domainScene,
            $context,
            $systemInstruction,
            'write_scene',
            'simple', // Forzamos simple mode para el turno 0
            $onChunk,
            $userId
        );

        $outputMd = trim($writerOutput['outputMd'] ?? '');
        $commitId = null;

        if ($outputMd !== '') {
            $updatedScene = new DomainScene(
                id: $domainScene->id,
                vaultId: $domainScene->vaultId,
                title: $domainScene->title,
                chapter: $domainScene->chapter,
                sceneNumber: $domainScene->sceneNumber,
                status: $domainScene->status,
                locationId: $domainScene->locationId,
                objective: $domainScene->objective,
                constraints: $domainScene->constraints,
                draft: trim((string) $domainScene->draft) . "\n\n" . $outputMd,
                characters: $domainScene->characters,
            );

            $this->sceneRepository->save($updatedScene);

            // Registrar en la continuidad si existe una activa para esta escena
            if ($continuityId) {
                // Asegurar que el estado inicial de la escena esté en la continuidad
                $this->continuityRepository->ensureSceneStateFromBase([
                    'continuityId' => $continuityId,
                    'scene' => $domainScene,
                ]);

                // Registrar el turno
                $this->continuityRepository->appendTurn([
                    'continuityId' => $continuityId,
                    'sceneId' => $scene->id,
                    'turnIndex' => 0,
                    'mode' => 'write_scene',
                    'userMessage' => '[SISTEMA: APERTURA DINÁMICA]',
                    'outputMd' => $outputMd,
                    'notes' => ['type' => 'bootstrap'],
                ]);

                // Actualizar el draft en el estado de la continuidad
                $this->continuityRepository->replaceSceneDraft([
                    'continuityId' => $continuityId,
                    'sceneId' => $scene->id,
                    'draftMd' => $updatedScene->draft,
                ]);

                // Crear el commit para el árbol de historia
                $commitData = $this->continuityRepository->createCommitFromCurrentState([
                    'continuityId' => $continuityId,
                    'sceneId' => $scene->id,
                    'parentCommitId' => null,
                    'turnIndex' => 0,
                    'mode' => 'write_scene',
                    'message' => 'Turno 0: Apertura de escena',
                ]);
                $commitId = $commitData['id'] ?? null;
            }
        }

        return [
            'outputMd' => $outputMd,
            'sceneId' => $scene->id,
            'continuityId' => $continuityId,
            'sceneType' => 'simple',
            'applied' => true,
            'commitId' => $commitId,
        ];
    }
}
