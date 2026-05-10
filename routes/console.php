<?php

use App\Application\UseCases\ImportLegacyCanonUseCase;
use App\Application\UseCases\CheckoutContinuityCommitUseCase;
use App\Application\UseCases\CreateContinuityBranchFromCommitUseCase;
use App\Application\UseCases\CreateContinuityBranchFromTurnUseCase;
use App\Application\UseCases\CreateContinuityBranchUseCase;
use App\Application\UseCases\GenerateContinuityTurnUseCase;
use App\Application\UseCases\GenerateSceneTurnUseCase;
use App\Application\UseCases\RewindContinuityToTurnUseCase;
use App\Jobs\IndexLoreEntryJob;
use App\Models\LoreEntry;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('historia:about', function () {
    $this->components->info('historia_pipeline Laravel bootstrap');
    $this->newLine();
    $this->line('Capas objetivo: Domain -> Application -> Infrastructure -> Http/Console');
    $this->line('Migracion base: docs/migration_from_node_v2.md');
    $this->line('Arquitectura objetivo: docs/architecture.md');
})->purpose('Describe la base Laravel para historia_pipeline');

Artisan::command('historia:import-legacy-canon {source} {vaultId} {--scope=canon_base+scenes}', function (
    ImportLegacyCanonUseCase $useCase
): void {
    $result = $useCase->execute(
        sourcePath: (string) $this->argument('source'),
        vaultId: (string) $this->argument('vaultId'),
        scope: (string) $this->option('scope'),
    );

    $this->components->info('Importacion completada');
    $this->table(
        ['segmento', 'conteo'],
        collect($result)->map(fn ($value, $key): array => [(string) $key, (string) $value])->values()->all()
    );
})->purpose('Importa canon base y escenas desde una SQLite legacy');

Artisan::command('historia:generate-scene {sceneId} {message} {--mode=write_scene} {--no-apply}', function (): void {
    /** @var GenerateSceneTurnUseCase $useCase */
    $useCase = app(GenerateSceneTurnUseCase::class);
    $result = $useCase->execute(
        sceneId: (string) $this->argument('sceneId'),
        userMessage: (string) $this->argument('message'),
        mode: (string) $this->option('mode'),
        apply: ! (bool) $this->option('no-apply'),
    );

    $this->components->info('Generacion completada');
    $this->line('sceneType: '.(string) $result['sceneType']);
    $this->line('');
    $this->line((string) $result['outputMd']);
})->purpose('Genera un turno de escena con la capa de IA estilo v2');

Artisan::command(
    'historia:continuity-turn {continuityId} {sceneId} {message} {--mode=write_scene} {--user-id=} {--no-apply}',
    function (): void {
        /** @var GenerateContinuityTurnUseCase $useCase */
        $useCase = app(GenerateContinuityTurnUseCase::class);
        $result = $useCase->execute(
            continuityId: (string) $this->argument('continuityId'),
            sceneId: (string) $this->argument('sceneId'),
            userMessage: (string) $this->argument('message'),
            mode: (string) $this->option('mode'),
            apply: ! (bool) $this->option('no-apply'),
            userId: $this->option('user-id') !== null ? (int) $this->option('user-id') : null,
        );

        $this->components->info('Turno de continuidad generado');
        $this->table(
            ['campo', 'valor'],
            [
                ['continuityId', (string) ($result['continuityId'] ?? '')],
                ['sceneId', (string) ($result['sceneId'] ?? '')],
                ['turnIndex', (string) ($result['turnIndex'] ?? '')],
                ['commitId', (string) ($result['commitId'] ?? '')],
                ['sceneType', (string) ($result['sceneType'] ?? '')],
                ['applied', (string) (($result['applied'] ?? false) ? 'true' : 'false')],
            ]
        );
        $this->line('');
        $this->line((string) ($result['outputMd'] ?? ''));
    }
)->purpose('Genera un turno de continuidad con commit y estado runtime');

Artisan::command(
    'historia:continuity-branch {parentContinuityId} {newContinuityId} {--label=} {--scene-id=} {--commit-id=} {--turn-index=}',
    function (): void {
        /** @var CreateContinuityBranchUseCase $createBranchUseCase */
        $createBranchUseCase = app(CreateContinuityBranchUseCase::class);
        /** @var CreateContinuityBranchFromCommitUseCase $createBranchFromCommitUseCase */
        $createBranchFromCommitUseCase = app(CreateContinuityBranchFromCommitUseCase::class);
        /** @var CreateContinuityBranchFromTurnUseCase $createBranchFromTurnUseCase */
        $createBranchFromTurnUseCase = app(CreateContinuityBranchFromTurnUseCase::class);
        $sceneId = $this->option('scene-id');
        $commitId = $this->option('commit-id');
        $turnIndex = $this->option('turn-index');

        if ($commitId !== null) {
            if ($sceneId === null || trim((string) $sceneId) === '') {
                $this->components->error('La opcion --scene-id es requerida cuando se usa --commit-id');

                return;
            }

            $result = $createBranchFromCommitUseCase->execute(
                parentContinuityId: (string) $this->argument('parentContinuityId'),
                newContinuityId: (string) $this->argument('newContinuityId'),
                sceneId: (string) $sceneId,
                commitId: (int) $commitId,
                label: $this->option('label') !== null ? (string) $this->option('label') : null,
            );
        } elseif ($turnIndex !== null) {
            if ($sceneId === null || trim((string) $sceneId) === '') {
                $this->components->error('La opcion --scene-id es requerida cuando se usa --turn-index');

                return;
            }

            $result = $createBranchFromTurnUseCase->execute(
                parentContinuityId: (string) $this->argument('parentContinuityId'),
                newContinuityId: (string) $this->argument('newContinuityId'),
                sceneId: (string) $sceneId,
                turnIndex: (int) $turnIndex,
                label: $this->option('label') !== null ? (string) $this->option('label') : null,
            );
        } else {
            $result = $createBranchUseCase->execute(
                parentContinuityId: (string) $this->argument('parentContinuityId'),
                newContinuityId: (string) $this->argument('newContinuityId'),
                label: $this->option('label') !== null ? (string) $this->option('label') : null,
            );
        }

        $this->components->info('Branch de continuidad creado');
        $this->table(
            ['campo', 'valor'],
            collect($result)->map(fn ($value, $key): array => [(string) $key, (string) $value])->values()->all()
        );
    }
)->purpose('Crea una branch de continuidad desde raiz, commit o turno');

Artisan::command(
    'historia:continuity-checkout {continuityId} {sceneId} {commitId}',
    function (): void {
        /** @var CheckoutContinuityCommitUseCase $useCase */
        $useCase = app(CheckoutContinuityCommitUseCase::class);
        $result = $useCase->execute(
            continuityId: (string) $this->argument('continuityId'),
            sceneId: (string) $this->argument('sceneId'),
            commitId: (int) $this->argument('commitId'),
        );

        $this->components->info('Checkout de continuidad completado');
        $this->table(
            ['campo', 'valor'],
            [
                ['continuityId', (string) ($result['continuityId'] ?? '')],
                ['sceneId', (string) ($result['sceneId'] ?? '')],
                ['commitId', (string) ($result['commitId'] ?? '')],
                ['restored', (string) (($result['restored'] ?? false) ? 'true' : 'false')],
            ]
        );
    }
)->purpose('Restaura la escena al snapshot de un commit de continuidad');

Artisan::command(
    'historia:continuity-rewind {continuityId} {sceneId} {turnIndex}',
    function (): void {
        /** @var RewindContinuityToTurnUseCase $useCase */
        $useCase = app(RewindContinuityToTurnUseCase::class);
        $result = $useCase->execute(
            continuityId: (string) $this->argument('continuityId'),
            sceneId: (string) $this->argument('sceneId'),
            turnIndex: (int) $this->argument('turnIndex'),
        );

        $this->components->info('Rewind de continuidad completado');
        $this->table(
            ['campo', 'valor'],
            [
                ['continuityId', (string) ($result['continuityId'] ?? '')],
                ['sceneId', (string) ($result['sceneId'] ?? '')],
                ['turnIndex', (string) ($result['turnIndex'] ?? '')],
                ['commitId', (string) ($result['commitId'] ?? '')],
                ['restored', (string) (($result['restored'] ?? false) ? 'true' : 'false')],
            ]
        );
    }
)->purpose('Revierte una continuidad al commit resuelto por turno');

Artisan::command(
    'historia:seed-lore-batch {file} {vaultId}',
    function (): void {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->components->error("Archivo no encontrado: {$file}");
            return;
        }

        $entries = json_decode((string) file_get_contents($file), true);

        if (! is_array($entries) || empty($entries)) {
            $this->components->error('El archivo JSON no contiene entradas válidas.');
            return;
        }

        $vaultId = (string) $this->argument('vaultId');

        // Persist all LoreEntry records first, then batch-index asynchronously
        $loreIds = collect($entries)->map(function (array $item) use ($vaultId): int {
            $entry = LoreEntry::query()->create([
                'vault_id' => $vaultId,
                'content'  => $item['content'],
                'metadata' => $item['metadata'] ?? [],
            ]);

            return (int) $entry->id;
        });

        $jobs = $loreIds->map(fn(int $id) => new IndexLoreEntryJob($id))->all();

        $batchName = 'seed-lore-' . now()->format('YmdHis');

        Bus::batch($jobs)
            ->name($batchName)
            ->then(function (Batch $batch) use ($loreIds): void {
                Log::info('Batch de lore completado.', [
                    'batch_id' => $batch->id,
                    'total'    => $batch->totalJobs,
                    'lore_ids' => $loreIds->take(5)->all(),
                ]);
            })
            ->catch(function (Batch $batch, \Throwable $e): void {
                Log::error('Batch de lore con fallos.', [
                    'batch_id'  => $batch->id,
                    'failed'    => $batch->failedJobs,
                    'error'     => $e->getMessage(),
                ]);
            })
            ->onQueue('heavy')
            ->dispatch();

        $this->components->info("Batch '{$batchName}' enviado: {$loreIds->count()} entradas en cola.");
    }
)->purpose('Importa lore desde JSON y lo indexa en Qdrant via Bus::batch asíncrono');

// ── Horizon metrics snapshot ──────────────────────────────────────────────────
Schedule::command('horizon:snapshot')->everyFiveMinutes();
