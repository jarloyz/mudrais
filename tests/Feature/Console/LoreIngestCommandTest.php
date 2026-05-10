<?php

namespace Tests\Feature\Console;

use App\Application\Services\VectorRetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LoreIngestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fails_if_file_does_not_exist()
    {
        $this->artisan('lore:ingest', ['vault_id' => 'test_vault', 'file' => 'non_existent_file.json'])
            ->expectsOutputToContain('El archivo no existe')
            ->assertFailed();
    }

    public function test_it_ingests_valid_json()
    {
        $filePath = storage_path('app/testing_lore.json');
        $json = json_encode([
            [
                'content' => 'This is a test lore entry.',
                'metadata' => ['tags' => ['test', 'lore']]
            ]
        ]);
        File::put($filePath, $json);

        $mockService = $this->createMock(VectorRetrievalService::class);
        $mockService->expects($this->once())
            ->method('addEntry')
            ->with('test_vault', 'This is a test lore entry.', [
                'tags' => ['test', 'lore'],
                'requirements' => [
                    'intimacy_min' => 0,
                    'wealth_min' => 0,
                    'influence_min' => 0,
                    'required_quest_flag' => null,
                ]
            ]);

        $this->app->instance(VectorRetrievalService::class, $mockService);

        $this->artisan('lore:ingest', ['vault_id' => 'test_vault', 'file' => $filePath])
            ->expectsOutputToContain('Ingesta completada.')
            ->expectsOutputToContain('Exitosos: 1')
            ->assertSuccessful();


        File::delete($filePath);
    }
}
