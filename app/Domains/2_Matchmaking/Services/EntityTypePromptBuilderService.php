<?php

namespace App\Domains\Matchmaking\Services;

use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use App\Domains\Matchmaking\Models\ArchetypeMutator;
use App\Domains\Narrative\Models\Vault;
use App\Infrastructure\Ai\PromptPlaceholder;
use Illuminate\Support\Facades\Log;

class EntityTypePromptBuilderService
{
    public function extractSoftFields(ArchetypeEntityType $entityType, array $contentRaw): array
    {
        Log::debug('[EntityTypePromptBuilderService@extractSoftFields]', [
            'entity_type_id' => $entityType->id,
            'context'        => $entityType->getMutatorContext(),
        ]);

        return ArchetypeMutator::where('archetype_entity_type_id', $entityType->id)
            ->where('context', $entityType->getMutatorContext())
            ->whereIn('storage_mode', ['semantic', 'both'])
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn($m) => [$m->field_label => $contentRaw[$m->field_key] ?? null])
            ->filter()
            ->all();
    }

    public function buildPrompt(ArchetypeEntityType $entityType, array $softFields, ?Vault $vault = null): string
    {
        Log::debug('[EntityTypePromptBuilderService@buildPrompt]', [
            'entity_type_id'   => $entityType->id,
            'soft_field_count' => count($softFields),
            'vault_id'         => $vault?->id,
        ]);

        $template = (string) $entityType->system_prompt;

        if (blank($template)) {
            Log::warning('[EntityTypePromptBuilderService@buildPrompt] system_prompt vacío', [
                'entity_type_id' => $entityType->id,
            ]);
            return '';
        }

        $this->validateRequiredPlaceholders($template, $entityType->id);

        $archetypePrompt = $this->resolveContextInjection($entityType);

        $vaultContext = $vault
            ? "Name: {$vault->name}\nDescription: {$vault->description}"
            : '';

        $encodedFields = json_encode($softFields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        // {user_soft_data_json} kept for backward-compat during migration; new prompts must use {context_data_json}
        $built = str_replace(
            [
                PromptPlaceholder::ContextData->value,
                PromptPlaceholder::UserSoftData->value,
                PromptPlaceholder::ArchetypeInjection->value,
                PromptPlaceholder::VaultContext->value,
            ],
            [
                $encodedFields,
                $encodedFields,
                $archetypePrompt,
                $vaultContext,
            ],
            $template
        );

        $this->detectUnreplacedPlaceholders($built, $entityType->id);

        return $built;
    }

    /**
     * Resuelve la inyección de dominio para {archetype_prompt_injection}.
     *
     * Prioridad:
     *   1. archetype_prompts WHERE agent_type = 'context_injection'  ← solo reglas de dominio, sin formato
     *   2. archetype_prompts WHERE agent_type = 'optimizer'           ← fallback legacy
     *   3. '' (vacío)
     */
    private function resolveContextInjection(ArchetypeEntityType $entityType): string
    {
        $archetype = $entityType->archetype;

        $injection = $archetype->prompts()
            ->where('agent_type', 'context_injection')
            ->value('system_prompt');

        if (filled($injection)) {
            Log::debug('[EntityTypePromptBuilderService] Usando context_injection para archetype_prompt_injection.', [
                'entity_type_id' => $entityType->id,
                'archetype_id'   => $archetype->id,
            ]);
            return $injection;
        }

        $legacy = $archetype->prompts()
            ->where('agent_type', 'optimizer')
            ->value('system_prompt');

        if (filled($legacy)) {
            Log::warning('[EntityTypePromptBuilderService] Usando optimizer como fallback para context_injection — crear prompt context_injection para este archetype.', [
                'entity_type_id' => $entityType->id,
                'archetype_id'   => $archetype->id,
                'hint'           => 'Ver docs/prompt-flow.md — sección Contrato de placeholders',
            ]);
            return $legacy;
        }

        Log::debug('[EntityTypePromptBuilderService] Sin context_injection ni optimizer — {archetype_prompt_injection} queda vacío.', [
            'entity_type_id' => $entityType->id,
            'archetype_id'   => $archetype->id,
        ]);
        return '';
    }

    private function validateRequiredPlaceholders(string $template, string $entityTypeId): void
    {
        foreach (PromptPlaceholder::requiredForEntityType() as $placeholder) {
            if (! str_contains($template, $placeholder)) {
                Log::error('[EntityTypePromptBuilderService@buildPrompt] Placeholder requerido ausente en system_prompt', [
                    'entity_type_id'      => $entityTypeId,
                    'missing_placeholder' => $placeholder,
                    'hint'                => 'Ver docs/prompt-flow.md — sección Contrato de placeholders',
                ]);
            }
        }

        $hasDataPlaceholder = collect(PromptPlaceholder::dataPlaceholders())
            ->some(fn($p) => str_contains($template, $p));

        if (! $hasDataPlaceholder) {
            Log::error('[EntityTypePromptBuilderService@buildPrompt] Ningún placeholder de datos encontrado en system_prompt', [
                'entity_type_id' => $entityTypeId,
                'expected_one_of' => PromptPlaceholder::dataPlaceholders(),
                'hint'           => 'Ver docs/prompt-flow.md — sección Contrato de placeholders',
            ]);
        }
    }

    private function detectUnreplacedPlaceholders(string $built, string $entityTypeId): void
    {
        foreach (PromptPlaceholder::allValues() as $placeholder) {
            if (str_contains($built, $placeholder)) {
                Log::warning('[EntityTypePromptBuilderService@buildPrompt] Placeholder no reemplazado — posible typo en system_prompt', [
                    'entity_type_id'        => $entityTypeId,
                    'unreplaced_placeholder' => $placeholder,
                    'hint'                  => 'Ver docs/prompt-flow.md — sección Contrato de placeholders',
                ]);
            }
        }
    }
}
