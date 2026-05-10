<?php

namespace App\Domains\Matchmaking\Services;

use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use App\Domains\Matchmaking\Models\ArchetypeMutator;
use App\Infrastructure\Discord\Modals\RegistroModals;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ArchetypeMutatorService
{
    // Campos base de step1 que siempre aparecen (3 de 5 slots Discord).
    private const BASE_STEP1_SLOTS = 3;
    // Discord permite máximo 5 componentes por modal.
    private const DISCORD_MODAL_MAX = 5;

    /**
     * Devuelve los mutators de un arquetipo para un contexto dado, ordenados por sort_order.
     *
     * @return Collection<ArchetypeMutator>
     */
    public function getFieldsForContext(string $archetypeId, string $context): Collection
    {
        Log::debug('[ArchetypeMutatorService@getFieldsForContext]', [
            'archetype_id' => $archetypeId,
            'context'      => $context,
        ]);

        return ArchetypeMutator::where('archetype_id', $archetypeId)
            ->where('context', $context)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Convierte los mutators de un contexto en filas de componentes Discord (text inputs).
     * Discord modals solo soportan type:4 (text input), por lo que los tipos select/boolean/number
     * se convierten a text inputs con placeholder descriptivo.
     *
     * @return list<array> Cada elemento es una fila Discord: {type:1, components:[...]}
     */
    public function buildDiscordComponents(string $archetypeId, string $context, array $prefill = []): array
    {
        $mutators = $this->getFieldsForContext($archetypeId, $context);

        Log::debug('[ArchetypeMutatorService@buildDiscordComponents] Mutators encontrados', [
            'archetype_id' => $archetypeId,
            'context'      => $context,
            'count'        => $mutators->count(),
        ]);

        return $mutators->map(fn (ArchetypeMutator $m) => [
            'type'       => 1,
            'components' => [array_filter([
                'type'        => 4,
                'custom_id'   => substr($m->field_key, 0, 100),
                'label'       => mb_substr($m->field_label, 0, 45),
                'style'       => $m->field_type === 'text_long' ? 2 : 1,
                'required'    => $m->is_required,
                'placeholder' => mb_substr($this->buildPlaceholder($m), 0, 100) ?: null,
                'max_length'  => $m->field_type === 'number' ? 10 : ($m->field_type === 'text_long' ? 1000 : 200),
                'value'       => $prefill[$m->field_key] ?? null,
            ], fn ($v) => $v !== null && $v !== '')],
        ])->values()->all();
    }

    /**
     * Devuelve todas las páginas del Modal 2 (cada página = array de filas, máx. 5).
     * Soporta arquetipos con más de 5 mutadores mediante paginación.
     */
    /**
     * Devuelve todas las páginas del Modal 2 (cada página = array de filas, máx. 5).
     * Soporta arquetipos con más de 5 mutadores mediante paginación y agrupamiento modal.
     */
    public function buildStep2ModalPages(?string $archetypeId, array $prefill = []): array
    {
        Log::debug('[ArchetypeMutatorService@buildStep2ModalPages]', [
            'archetype_id' => $archetypeId,
        ]);

        if (!$archetypeId) {
            return array_chunk($this->defaultStep2Rows($prefill), self::DISCORD_MODAL_MAX);
        }

        $mutators = $this->getFieldsForContext($archetypeId, 'registration');

        if ($mutators->isEmpty()) {
            return array_chunk($this->defaultStep2Rows($prefill), self::DISCORD_MODAL_MAX);
        }

        // ── Lógica Multimodal: Agrupar por modal_group ───────────────────────
        $inlineRows = [];
        $groupedRows = []; // ['group_key' => [row, ...]]

        foreach ($mutators as $m) {
            $row = $this->buildMutatorDiscordRow($m, $prefill);

            $group = $m->modal_group;
            if (blank($group)) {
                $inlineRows[] = $row;
            } else {
                $groupedRows[$group][] = $row;
            }
        }

        // Página 1: campos inline (sin group), chunked si >5
        $pages = !empty($inlineRows) ? array_chunk($inlineRows, self::DISCORD_MODAL_MAX) : [];

        // Páginas adicionales: una por cada modal_group
        foreach ($groupedRows as $group) {
            foreach (array_chunk($group, self::DISCORD_MODAL_MAX) as $chunk) {
                $pages[] = $chunk;
            }
        }

        if (empty($pages)) {
            return array_chunk($this->defaultStep2Rows($prefill), self::DISCORD_MODAL_MAX);
        }

        return $pages;
    }

    /**
     * Construye el modal completo de step2 para una página específica.
     */
    public function buildStep2Modal(?string $archetypeId, array $prefill = [], int $page = 0): array
    {
        Log::debug('[ArchetypeMutatorService@buildStep2Modal]', [
            'archetype_id' => $archetypeId,
            'page'         => $page,
        ]);

        $pages = $this->buildStep2ModalPages($archetypeId, $prefill);
        $total = count($pages);
        $currentPage = $pages[$page] ?? $pages[0] ?? [];

        return [
            'custom_id'  => "mudrais_registro_step_2:{$page}:{$archetypeId}",
            'title'      => $total > 1 ? "Ficha de Arquetipo (" . ($page + 1) . "/{$total})" : 'Ficha de Arquetipo',
            'components' => $currentPage,
        ];
    }

    /**
     * Construye el modal completo de step1 con el género pre-seleccionado.
     * Soporta prefill para edición o selección previa de género vía botón.
     */
    public function buildStep1Modal(?int $archetypeId, array $prefill = []): array
    {
        Log::debug('[ArchetypeMutatorService@buildStep1Modal]', [
            'archetype_id' => $archetypeId,
            'prefill_keys' => array_keys($prefill),
        ]);

        return RegistroModals::step1(prefill: $prefill);
    }

    /**
     * Campos por defecto para el step 2.
     */
    private function defaultStep2Rows(array $prefill): array
    {
        return [
            [
                'type'      => 18,
                'label'     => 'Absolute Limits (Red)',
                'component' => array_filter([
                    'type'        => 4,
                    'custom_id'   => 'red_lines',
                    'style'       => 2,
                    'placeholder' => 'Topics forbidden for you. You will never see games with these.',
                    'required'    => false,
                    'value'       => $prefill['red_lines'] ?? null,
                ], fn ($v) => $v !== null),
            ],
            [
                'type'      => 18,
                'label'     => 'Topics to Avoid (Yellow)',
                'component' => array_filter([
                    'type'        => 4,
                    'custom_id'   => 'yellow_lines',
                    'style'       => 2,
                    'placeholder' => 'Max 10, ordered from most to least unpleasant.',
                    'required'    => false,
                    'value'       => $prefill['yellow_lines'] ?? null,
                ], fn ($v) => $v !== null),
            ],
            [
                'type'      => 18,
                'label'     => 'Your Favorites',
                'component' => array_filter([
                    'type'        => 4,
                    'custom_id'   => 'preferences',
                    'style'       => 2,
                    'placeholder' => 'Genres, tropes or themes. Max 10, ordered by preference.',
                    'required'    => true,
                    'value'       => $prefill['preferences'] ?? null,
                ], fn ($v) => $v !== null),
            ],
            [
                'type'      => 18,
                'label'     => 'Your Style Summary',
                'component' => array_filter([
                    'type'        => 4,
                    'custom_id'   => 'style',
                    'style'       => 2,
                    'placeholder' => 'Be direct. E.g. 3rd person, psychological drama, slow burn...',
                    'required'    => true,
                    'max_length'  => 300,
                    'value'       => $prefill['style'] ?? null,
                ], fn ($v) => $v !== null),
            ],
            [
                'type'      => 18,
                'label'     => 'Availability / Schedule',
                'component' => array_filter([
                    'type'        => 4,
                    'custom_id'   => 'schedule_raw',
                    'style'       => 1,
                    'placeholder' => 'E.g. weekends, evenings UTC-5, ~3h/week',
                    'required'    => false,
                    'max_length'  => 200,
                    'value'       => $prefill['schedule_raw'] ?? null,
                ], fn ($v) => $v !== null),
            ],
        ];
    }

    /**
     * Construye los componentes para los pasos de creación de Vault.
     * Devuelve un array de páginas, cada una con un array de componentes.
     */
    public function buildVaultModalPages(string $archetypeId, array $prefill = []): array
    {
        $mutatorRows = $this->buildDiscordComponents($archetypeId, 'vault', $prefill);

        $baseRows = [
            [
                'type'       => 1,
                'components' => [array_filter([
                    'type'        => 4,
                    'custom_id'   => 'vault_name',
                    'label'       => 'Nombre del Vault',
                    'style'       => 1, // Short
                    'placeholder' => 'Ej: El Refugio de los Perdidos',
                    'min_length'  => 3,
                    'max_length'  => 100,
                    'required'    => true,
                    'value'       => $prefill['vault_name'] ?? null,
                ], fn ($v) => $v !== null)],
            ],
            [
                'type'       => 1,
                'components' => [array_filter([
                    'type'        => 4,
                    'custom_id'   => 'vault_description',
                    'label'       => 'Descripción del Vault',
                    'style'       => 2, // Paragraph
                    'placeholder' => 'Describe de qué trata este vault...',
                    'min_length'  => 10,
                    'max_length'  => 1000,
                    'required'    => true,
                    'value'       => $prefill['vault_description'] ?? null,
                ], fn ($v) => $v !== null)],
            ],
        ];

        $allRows = array_merge($baseRows, $mutatorRows);
        return array_chunk($allRows, self::DISCORD_MODAL_MAX);
    }

    /**
     * Construye las páginas del modal de creación de Contexto (Avatar).
     * Consulta mutators por archetype_entity_type_id con el context del EntityType.
     *
     * Reglas de paginación:
     *   - Página 1: campo "Nombre" (base) + campos SIN modal_group (inline), chunked si >5.
     *   - Páginas siguientes: una por cada valor distinto de modal_group,
     *     en el orden de aparición según sort_order, también chunked si >5.
     *
     * Los campos select se representan como text input con las opciones en el placeholder,
     * ya que Discord modals no admiten select menus (solo type:4).
     *
     * @return list<list<array>> Páginas de filas Discord (máx. DISCORD_MODAL_MAX por página)
     */
    public function buildContextModalPages(string $archetypeEntityTypeId, array $prefill = []): array
    {
        Log::debug('[ArchetypeMutatorService@buildContextModalPages]', [
            'archetype_entity_type_id' => $archetypeEntityTypeId,
        ]);

        $entityType = ArchetypeEntityType::find($archetypeEntityTypeId);
        $context    = $entityType?->getMutatorContext() ?? 'avatar_context';

        $mutators = ArchetypeMutator::where('archetype_entity_type_id', $archetypeEntityTypeId)
            ->where('context', $context)
            ->orderBy('sort_order')
            ->get();

        $nameRow = [
            'type'      => 18,
            'label'     => 'Nombre',
            'component' => array_filter([
                'type'        => 4,
                'custom_id'   => 'context_name',
                'style'       => 1,
                'placeholder' => 'Ej: Kael el Errante',
                'min_length'  => 2,
                'max_length'  => 100,
                'required'    => true,
                'value'       => $prefill['context_name'] ?? null,
            ], fn ($v) => $v !== null),
        ];

        // Separa campos inline (sin group) de los agrupados, preservando sort_order
        $inlineRows = [];
        $groupedRows = []; // ['group_key' => [row, ...]] en orden de primera aparición

        foreach ($mutators as $m) {
            $row = $this->buildMutatorDiscordRow($m, $prefill);
            $group = $m->modal_group;

            if (blank($group)) {
                $inlineRows[] = $row;
            } else {
                $groupedRows[$group][] = $row;
            }
        }

        // Página 1: nombre + campos inline (se parte si excede el límite de Discord)
        $page1 = array_merge([$nameRow], $inlineRows);
        $pages  = array_chunk($page1, self::DISCORD_MODAL_MAX);

        // Páginas adicionales: una por cada modal_group (chunked si >5 campos)
        foreach ($groupedRows as $group) {
            foreach (array_chunk($group, self::DISCORD_MODAL_MAX) as $chunk) {
                $pages[] = $chunk;
            }
        }

        Log::debug('[ArchetypeMutatorService@buildContextModalPages] Páginas generadas', [
            'archetype_entity_type_id' => $archetypeEntityTypeId,
            'inline_rows'              => count($inlineRows),
            'groups'                   => array_keys($groupedRows),
            'pages'                    => count($pages),
        ]);

        return $pages;
    }

    /**
     * Construye una Section (type:18) para un mutator, válido en modals de Discord.
     *
     *   - type:3  String Select  → 'select' y 'boolean'
     *   - type:4  Text Input     → textos, number y range
     */
    public function buildMutatorDiscordRow(ArchetypeMutator $m, array $prefill): array
    {
        $customId = substr($m->field_key, 0, 100);
        $label    = mb_substr($m->field_label, 0, 45);
        $value    = isset($prefill[$m->field_key]) ? (string) $prefill[$m->field_key] : null;

        $inner = match ($m->field_type) {

            'select' => $this->buildSelectComponent($m, $customId),

            'boolean' => [
                'type'        => 3,
                'custom_id'   => $customId,
                'placeholder' => 'Selecciona...',
                'min_values'  => $m->is_required ? 1 : 0,
                'max_values'  => 1,
                'options'     => [
                    ['label' => 'Sí', 'value' => 'si'],
                    ['label' => 'No', 'value' => 'no'],
                ],
            ],

            'number', 'range' => array_filter([
                'type'        => 4,
                'custom_id'   => $customId,
                'style'       => 1,
                'required'    => $m->is_required,
                'placeholder' => $this->buildIntegerPlaceholder($m),
                'max_length'  => 10,
                'value'       => $value,
            ], fn ($v) => $v !== null && $v !== ''),

            'text_long' => array_filter([
                'type'        => 4,
                'custom_id'   => $customId,
                'style'       => 2,
                'required'    => $m->is_required,
                'placeholder' => mb_substr($m->field_placeholder ?? '', 0, 100) ?: null,
                'min_length'  => $m->options['min_length'] ?? null,
                'max_length'  => $m->options['max_length'] ?? 1000,
                'value'       => $value,
            ], fn ($v) => $v !== null && $v !== ''),

            default => array_filter([
                'type'        => 4,
                'custom_id'   => $customId,
                'style'       => 1,
                'required'    => $m->is_required,
                'placeholder' => mb_substr($m->field_placeholder ?? '', 0, 100) ?: null,
                'min_length'  => $m->options['min_length'] ?? null,
                'max_length'  => $m->options['max_length'] ?? 200,
                'value'       => $value,
            ], fn ($v) => $v !== null && $v !== ''),
        };

        return [
            'type'      => 18,
            'label'     => $label,
            'component' => $inner,
        ];
    }

    /**
     * Placeholder para campos numéricos/rango: indica que se espera un entero
     * y opcionalmente el rango permitido.
     */
    private function buildIntegerPlaceholder(ArchetypeMutator $m): string
    {
        $min = $m->options['min'] ?? null;
        $max = $m->options['max'] ?? null;

        if ($min !== null && $max !== null) {
            return "Entero entre {$min} y {$max}";
        }

        return 'Escribe un entero';
    }

    private function buildSelectComponent(ArchetypeMutator $m, string $customId): array
    {
        $items = collect($m->options['items'] ?? [])
            ->map(fn ($item) => array_filter([
                'label'       => mb_substr((string) ($item['label'] ?? ''), 0, 100),
                'value'       => mb_substr((string) ($item['value'] ?? ''), 0, 100),
                'description' => isset($item['description'])
                    ? mb_substr((string) $item['description'], 0, 100)
                    : null,
            ], fn ($v) => $v !== null && $v !== ''))
            ->values()
            ->all();

        $maxValues = min(
            max(1, (int) ($m->options['max_values'] ?? 1)),
            max(1, count($items))
        );

        return [
            'type'        => 3,
            'custom_id'   => $customId,
            'placeholder' => mb_substr($m->options['placeholder'] ?? 'Selecciona una opción...', 0, 150),
            'min_values'  => $m->is_required ? 1 : 0,
            'max_values'  => $maxValues,
            'options'     => $items,
        ];
    }

    /**
     * Genera el placeholder de un mutator según su tipo y opciones.
     *
     * Las opciones de select se almacenan bajo options['items']
     * (estructura de Filament: [{value, label}, ...]).
     */
    private function buildPlaceholder(ArchetypeMutator $mutator): string
    {
        return match ($mutator->field_type) {
            'select'  => 'Opciones: ' . collect($mutator->options['items'] ?? [])
                ->pluck('label')
                ->implode(' | '),
            'number'  => 'Escribe un número',
            'boolean' => 'Escribe: si o no',
            default   => $mutator->field_placeholder ?? '',
        };
    }
}
