<?php

namespace App\Http\Controllers\Api\V2;

use App\Models\Avatar;
use App\Models\Continuity;
use App\Models\ContinuityQuestStatus;
use App\Models\Location;
use App\Models\Quest;
use App\Models\QuestStep;
use App\Models\Activity;
use App\Models\SceneActiveContinuity;
use Illuminate\Database\Seeder;

class DemoScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $location = Location::query()->updateOrCreate(
            ['id' => 'refugio_entrada'],
            [
                'vault_id' => 'vault_demo',
                'name' => 'Entrada del refugio',
                'context_id' => null,
            ]
        );

        $scene = Activity::query()->updateOrCreate(
            ['id' => 'escena_prueba'],
            [
                'vault_id' => 'vault_demo',
                'title' => 'Escena de prueba',
                'chapter' => 1,
                'scene_number' => 1,
                'status' => 'draft',
                'location_id' => $location->id,
                'objective' => 'Abrir una salida segura del refugio.',
                'constraints' => implode("\n", [
                    'Locacion base: Entrada del refugio.',
                    'Quest activa base: Fuga del refugio.',
                    'Objetivo inicial verificable: Identifica una via de escape y evalua al guardia.',
                ]),
                'draft' => implode("\n", [
                    '# Escena de prueba',
                    '',
                    'La escena arranca en la Entrada del refugio.',
                    'La presion narrativa principal es la quest "Fuga del refugio".',
                    'Objetivo inmediato: Identifica una via de escape y evalua al guardia.',
                    'El ambiente ya esta cargado y la situacion exige una primera reaccion concreta.',
                ]),
            ]
        );

        $quest = Quest::query()->updateOrCreate(
            ['id' => 'fuga_del_refugio'],
            [
                'vault_id' => 'vault_demo',
                'title' => 'Fuga del refugio',
                'description' => 'Escapa del refugio sin entregar tu posicion a la faccion que lo vigila.',
                'type' => 'main',
                'status' => 'active',
            ]
        );

        $steps = [
            10 => 'Identifica una via de escape y evalua al guardia.',
            20 => 'Neutraliza o evade al guardia de la salida.',
            30 => 'Cruza el umbral y asegura una ruta inmediata.',
        ];

        foreach ($steps as $stageNumber => $description) {
            QuestStep::query()->updateOrCreate(
                [
                    'quest_id' => $quest->id,
                    'stage_number' => $stageNumber,
                ],
                [
                    'description' => $description,
                    'is_optional' => false,
                ]
            );
        }

        $character = Avatar::query()->updateOrCreate(
            ['id' => 'lucia_demo'],
            [
                'name' => 'Lucia Varela',
                'vault_id' => 'vault_demo',
                'public_facade' => implode("\n", [
                    'Voz y Tono:',
                    '- Habla con un tono seco y directo, sin rodeos.',
                    '- Suele soltar suspiros cuando esta frustrada.',
                    '',
                    'Apariencia Fisica:',
                    '- Lleva una chaqueta militar desgastada.',
                ]),
            ]
        );

        $scene->avatars()->syncWithoutDetaching([
            $character->id => ['role' => 'protagonist'],
        ]);

        $continuity = Continuity::query()->updateOrCreate(
            ['id' => 'cont_demo'],
            [
                'parent_id' => null,
                'root_id' => 'cont_demo',
                'label' => 'Continuidad Demo',
                'status' => 'active',
                'archived_at' => null,
            ]
        );

        SceneActiveContinuity::query()->updateOrCreate(
            ['activity_id' => $scene->id],
            [
                'continuity_id' => $continuity->id,
                'continuity_commit_id' => null,
            ]
        );

        ContinuityQuestStatus::query()->updateOrCreate(
            [
                'continuity_id' => $continuity->id,
                'quest_id' => $quest->id,
            ],
            [
                'activity_id' => $scene->id,
                'status' => 'active',
                'current_stage_number' => 10,
                'ai_summary' => 'La salida sigue bloqueada por un guardia armado en la entrada.',
            ]
        );

        $this->command?->info('DemoScenarioSeeder: vault_demo listo con location, escena, quest, personaje y continuidad.');
    }
}
