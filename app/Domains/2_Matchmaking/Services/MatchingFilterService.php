<?php

namespace App\Domains\Matchmaking\Services;

use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve los filtros declarativos de un activity entity_type contra perfiles de jugadores.
 *
 * Cada regla en matching_filters tiene la forma:
 *   { "profile_field": "is_writer", "operator": "eq", "value": true }
 *
 * Operadores soportados: eq
 * El servicio hace pre-filtro en PostgreSQL (JSONB) y retorna los IDs de perfiles
 * que cumplen todas las reglas. Esos IDs se usan como filtro duro antes del semantic search.
 *
 * Referencia: docs/prompt-architecture.md — sección Activity matching filters
 */
class MatchingFilterService
{
    /**
     * Retorna los IDs de PlayerArchetypeProfile que cumplen las matching_filters del entity_type.
     * Si no hay filtros definidos, retorna array vacío (sin restricción).
     *
     * @return list<string>
     */
    public function resolveProfileIds(ArchetypeEntityType $entityType, string $archetypeId): array
    {
        Log::debug('[MatchingFilterService@resolveProfileIds]', [
            'entity_type_id' => $entityType->id,
            'archetype_id'   => $archetypeId,
        ]);

        $filters = $entityType->matching_filters ?? [];

        if (empty($filters)) {
            Log::debug('[MatchingFilterService@resolveProfileIds] Sin matching_filters — sin pre-filtro.', [
                'entity_type_id' => $entityType->id,
            ]);
            return [];
        }

        $query = PlayerArchetypeProfile::where('archetype_id', $archetypeId);

        foreach ($filters as $rule) {
            $field    = $rule['profile_field'] ?? null;
            $operator = $rule['operator'] ?? 'eq';
            $value    = $rule['value'] ?? null;

            if ($field === null) {
                Log::warning('[MatchingFilterService@resolveProfileIds] Regla sin profile_field — ignorada.', [
                    'rule' => $rule,
                ]);
                continue;
            }

            Log::debug('[MatchingFilterService@resolveProfileIds] Aplicando regla.', [
                'field'    => $field,
                'operator' => $operator,
                'value'    => $value,
            ]);

            match ($operator) {
                'eq' => $query->whereRaw(
                    "(content_raw->>?)::text = ?",
                    [$field, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value]
                ),
                default => Log::warning('[MatchingFilterService@resolveProfileIds] Operador no soportado — ignorado.', [
                    'operator' => $operator,
                ]),
            };
        }

        $ids = $query->pluck('id')->all();

        Log::info('[MatchingFilterService@resolveProfileIds] Perfiles pre-filtrados.', [
            'entity_type_id' => $entityType->id,
            'matched_count'  => count($ids),
        ]);

        return $ids;
    }
}
