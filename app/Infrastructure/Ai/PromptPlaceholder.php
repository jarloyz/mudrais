<?php

namespace App\Infrastructure\Ai;

/**
 * Nombres canónicos de placeholders para prompts de IA.
 *
 * REGLA: Usar siempre estos valores en los system_prompts de DB.
 * Un nombre distinto falla silenciosamente — el LLM recibe el placeholder literal sin datos.
 *
 * Referencia completa: docs/prompt-flow.md
 */
enum PromptPlaceholder: string
{
    // ─── ContextOptimizer (archetype_entity_types.system_prompt) ────────────────

    /**
     * Campos semánticos del Context Entity (personaje, libro, juego, etc.).
     * Fuente: ArchetypeMutator (storage=semantic|both), contexto del entity_type.
     *
     * NOTA: Placeholder para entidades inmutables y compartibles (Avatar/Context).
     * NO usar {user_soft_data_json} aquí — ese pertenece al pipeline de perfil del jugador.
     */
    case ContextData = '{context_data_json}';

    /**
     * Datos del perfil semántico del jugador. Solo para pipeline de perfil (ProfileOptimizerAgent).
     * Fuente: PlayerArchetypeProfile.positive_prefs + raw_profile.
     *
     * @deprecated en entity_type system_prompts — usar {context_data_json} para Context Entities.
     */
    case UserSoftData = '{user_soft_data_json}';

    /**
     * Reglas de dominio del archetype. Fuente (en orden de prioridad):
     *   1. archetype_prompts WHERE agent_type = 'context_injection'  ← solo terminología y dimensiones
     *   2. archetype_prompts WHERE agent_type = 'optimizer'           ← fallback legacy (WARNING en logs)
     */
    case ArchetypeInjection = '{archetype_prompt_injection}';

    /** Nombre y descripción del Vault asociado. Solo para entity = activity. */
    case VaultContext = '{vault_context}';

    // ─── Gatekeeper (archetype_prompts.gatekeeper) ──────────────────────────────

    /** JSON parcial del perfil ya extraído. Fuente: GatekeeperProfileAgent. */
    case PartialJson = '{partial_json_payload}';

    /** Texto libre original del usuario. Fuente: GatekeeperProfileAgent. */
    case RawText = '{raw_text_payload}';

    // ─── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Placeholder de reglas de dominio — obligatorio en TODOS los entity_type system_prompts.
     * Los placeholders de datos ({context_data_json} / {user_soft_data_json}) se validan por separado
     * porque un entity_type puede usar uno o ambos según lo que necesite indexar.
     */
    public static function requiredForEntityType(): array
    {
        return [self::ArchetypeInjection->value];
    }

    /**
     * Placeholders de datos disponibles para entity_type system_prompts.
     * Al menos uno debe estar presente en el template:
     *
     * - {context_data_json}   → datos sobre la entidad en sí (personaje, libro, juego, ubicación).
     *                           Inmutable y compartible — no contaminar con datos del creador.
     * - {user_soft_data_json} → preferencias del usuario para esta actividad/búsqueda.
     *                           Solo aplicable a entity = activity.
     *
     * Ambos pueden coexistir en activities con contexto específico
     * (ej: "busco co-op para Elden Ring" → datos del juego + preferencias del jugador).
     */
    public static function dataPlaceholders(): array
    {
        return [self::ContextData->value, self::UserSoftData->value];
    }

    /** Placeholders obligatorios en archetype_prompts.gatekeeper. */
    public static function requiredForGatekeeper(): array
    {
        return [
            self::PartialJson->value,
            self::RawText->value,
        ];
    }

    /** Todos los valores como array de strings. */
    public static function allValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
