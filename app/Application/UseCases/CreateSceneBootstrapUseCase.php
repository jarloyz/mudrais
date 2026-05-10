<?php

namespace App\Application\UseCases;

use App\Application\Agents\QuestScaffolderAgent;
use App\Application\Contracts\ContinuityQuestStatusRepository;
use App\Application\Contracts\LocationRepository;
use App\Application\Contracts\QuestScaffoldingRepository;
use App\Application\Contracts\SceneRepository;
use App\Application\Contracts\StructuredLogger;
use App\Application\Contracts\VaultContextRepository;
use App\Domain\Scene\Activity;
use InvalidArgumentException;

final readonly class CreateSceneBootstrapUseCase
{
    public function __construct(
        private VaultContextRepository $vaultContextRepository,
        private LocationRepository $locationRepository,
        private SceneRepository $sceneRepository,
        private QuestScaffoldingRepository $questScaffoldingRepository,
        private QuestScaffolderAgent $questScaffolderAgent,
        private ApplyQuestProgressDirectiveUseCase $applyQuestProgressDirectiveUseCase,
        private ContinuityQuestStatusRepository $continuityQuestStatusRepository,
        private StructuredLogger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array
    {
        $sceneId = trim((string) ($input['scene_id'] ?? ''));
        $vaultId = trim((string) ($input['vault_id'] ?? ''));
        $locationId = trim((string) ($input['location_id'] ?? ''));
        $title = trim((string) ($input['title'] ?? ''));
        $questId = trim((string) ($input['quest_id'] ?? ''));
        $questPrompt = trim((string) ($input['quest_prompt'] ?? ''));
        $continuityId = trim((string) ($input['continuity_id'] ?? ''));
        $userId = isset($input['user_id']) ? (string) $input['user_id'] : null;

        if ($sceneId === '' || $vaultId === '' || $locationId === '') {
            throw new InvalidArgumentException('scene_id, vault_id y location_id son requeridos.');
        }

        if ($questId === '' && $questPrompt === '') {
            throw new InvalidArgumentException('Debes indicar quest_id o quest_prompt para bootstrap de escena.');
        }

        $logger = $this->logger->withContext([
            'layer' => 'application',
            'use_case' => 'create_scene_bootstrap',
            'sceneId' => $sceneId,
            'vaultId' => $vaultId,
            'locationId' => $locationId,
            'continuityId' => $continuityId !== '' ? $continuityId : null,
            'userId' => $userId,
        ]);
        $logger->info('Inicio de bootstrap de escena con contexto');

        $vault = $this->vaultContextRepository->findVaultById($vaultId);
        if (! $vault) {
            throw new InvalidArgumentException('El vault seleccionado no existe.');
        }

        $location = $this->locationRepository->findById($locationId);
        if (! $location || $location->vaultId !== $vaultId) {
            throw new InvalidArgumentException('La locacion no existe o no pertenece al vault indicado.');
        }

        $scene = $this->sceneRepository->findById($sceneId);
        $sceneCreated = false;
        if (! $scene) {
            $sceneCreated = true;
        }

        $questBootstrap = $questId !== ''
            ? $this->questScaffoldingRepository->findForBootstrap($vaultId, $questId)
            : null;

        if ($questId !== '' && ! $questBootstrap) {
            throw new InvalidArgumentException('La quest indicada no existe o no pertenece al vault.');
        }

        if (! $questBootstrap) {
            $questBootstrap = $this->questScaffoldingRepository->saveGeneratedQuest(
                $vaultId,
                $this->questScaffolderAgent->generate($questPrompt, $userId)
            );
        }

        $sceneNumber = $scene?->sceneNumber ?? $this->nextSceneNumberForVault($vaultId);
        $sceneTitle = $title !== '' ? $title : ($scene?->title ?? $this->deriveSceneTitle($location->name, (string) ($questBootstrap['title'] ?? '')));
        $objective = (string) ($questBootstrap['currentObjective'] ?? $questBootstrap['title'] ?? '');
        $constraints = $this->buildConstraints(
            locationName: $location->name,
            questTitle: (string) ($questBootstrap['title'] ?? ''),
            currentObjective: $objective,
        );
        $draft = $this->buildDraft(
            sceneTitle: $sceneTitle,
            locationName: $location->name,
            questTitle: (string) ($questBootstrap['title'] ?? ''),
            currentObjective: $objective,
        );

        $sceneToSave = new Activity(
            id: $sceneId,
            vaultId: $vaultId,
            title: $sceneTitle,
            chapter: $scene?->chapter ?? 1,
            sceneNumber: $sceneNumber,
            status: $scene?->status ?? 'draft',
            locationId: $locationId,
            objective: $objective !== '' ? $objective : null,
            constraints: $constraints,
            draft: $draft,
            characters: $scene?->characters ?? [],
        );
        $this->sceneRepository->save($sceneToSave);

        $questStatusSeed = null;
        if ($continuityId !== '') {
            $existingTransition = $this->continuityQuestStatusRepository->getTransitionContext($continuityId, (string) $questBootstrap['questId']);
            if (($existingTransition['currentStageNumber'] ?? null) === null) {
                $questStatusSeed = $this->applyQuestProgressDirectiveUseCase->execute(
                    continuityId: $continuityId,
                    sceneId: $sceneId,
                    directive: [
                        'matched' => true,
                        'quest_id' => (string) $questBootstrap['questId'],
                        'advance_step' => false,
                        'new_stage_number' => $questBootstrap['firstStageNumber'] ?? null,
                        'new_status' => 'active',
                        'ai_summary' => 'Quest inicial sembrada durante bootstrap de escena.',
                    ],
                );
            }
        }

        $logger->info('Bootstrap de escena completado', [
            'sceneCreated' => $sceneCreated,
            'questId' => $questBootstrap['questId'] ?? null,
            'questGenerated' => (bool) ($questBootstrap['generated'] ?? false),
            'questStatusSeeded' => (bool) ($questStatusSeed['applied'] ?? false),
        ]);

        return [
            'sceneCreated' => $sceneCreated,
            'scene' => [
                'id' => $sceneId,
                'title' => $sceneTitle,
                'vault_id' => $vaultId,
                'location_id' => $locationId,
                'objective' => $objective,
                'constraints' => $constraints,
                'status' => $scene?->status ?? 'draft',
            ],
            'quest' => $questBootstrap,
            'questStatusSeed' => $questStatusSeed,
        ];
    }

    private function nextSceneNumberForVault(string $vaultId): int
    {
        $number = 1;
        while ($this->sceneRepository->findById("__probe_unused_{$vaultId}_{$number}") !== null) {
            $number++;
        }

        return \App\Infrastructure\Persistence\Eloquent\Models\SceneRecord::query()
            ->where('vault_id', $vaultId)
            ->count() + 1;
    }

    private function deriveSceneTitle(string $locationName, string $questTitle): string
    {
        $title = trim($questTitle) !== ''
            ? "{$locationName} - {$questTitle}"
            : "Escena en {$locationName}";

        return mb_substr($title, 0, 120);
    }

    private function buildConstraints(string $locationName, string $questTitle, string $currentObjective): string
    {
        return trim(implode("\n", array_filter([
            "Locacion base: {$locationName}.",
            $questTitle !== '' ? "Quest activa base: {$questTitle}." : null,
            $currentObjective !== '' ? "Objetivo inicial verificable: {$currentObjective}." : null,
            'Mantener continuidad inmediata con este objetivo sin arrancar la escena en vacio.',
        ])));
    }

    private function buildDraft(string $sceneTitle, string $locationName, string $questTitle, string $currentObjective): string
    {
        $lines = [
            "# {$sceneTitle}",
            '',
            "La escena arranca en {$locationName}.",
        ];

        if ($questTitle !== '') {
            $lines[] = "La presion narrativa principal es la quest \"{$questTitle}\".";
        }
        if ($currentObjective !== '') {
            $lines[] = "Objetivo inmediato: {$currentObjective}.";
        }

        $lines[] = 'El ambiente ya esta cargado y la situacion exige una primera reaccion concreta.';

        return implode("\n", $lines);
    }
}
