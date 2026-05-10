<?php

namespace App\Domains\Matchmaking\Observers;

use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\ArchetypeMutator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ArchetypeObserver
{
    private const BASE_MUTATORS = [
        [
            'context'           => 'registration',
            'field_key'         => 'red_lines',
            'field_label'       => 'Absolute Limits',
            'field_placeholder' => 'Forbidden topics. You will NEVER see games with these.',
            'field_type'        => 'text_long',
            'storage_mode'      => 'raw',
            'is_required'       => false,
            'sort_order'        => 0,
        ],
        [
            'context'           => 'registration',
            'field_key'         => 'yellow_lines',
            'field_label'       => 'Discomfort Zones',
            'field_placeholder' => 'Max 10, from LEAST to MOST unpleasant (comma-separated).',
            'field_type'        => 'text_long',
            'storage_mode'      => 'raw',
            'is_required'       => false,
            'sort_order'        => 1,
        ],
        [
            'context'           => 'registration',
            'field_key'         => 'preferences',
            'field_label'       => 'Key Preferences',
            'field_placeholder' => 'Max 10, from MOST to LEAST preferred (comma-separated).',
            'field_type'        => 'text_long',
            'storage_mode'      => 'semantic',
            'is_required'       => true,
            'sort_order'        => 2,
        ],
        [
            'context'           => 'registration',
            'field_key'         => 'style',
            'field_label'       => 'Your Narrative Style',
            'field_placeholder' => 'Describe your engagement and vibe (3rd person, drama, etc).',
            'field_type'        => 'text_long',
            'storage_mode'      => 'semantic',
            'is_required'       => true,
            'sort_order'        => 3,
        ],
    ];

    public function created(Archetype $archetype): void
    {
        Log::debug('[ArchetypeObserver@created] Iniciando creación de mutadores base', [
            'archetype_id' => $archetype->id,
            'name'         => $archetype->name,
        ]);

        $now = now();
        $mutators = array_map(function (array $base) use ($archetype, $now) {
            return array_merge($base, [
                'id'           => (string) Str::uuid7(),
                'archetype_id' => $archetype->id,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }, self::BASE_MUTATORS);

        ArchetypeMutator::insert($mutators);

        Log::info('[ArchetypeObserver@created] Mutadores base creados exitosamente', [
            'archetype_id' => $archetype->id,
            'count'        => count($mutators),
        ]);
    }
}
