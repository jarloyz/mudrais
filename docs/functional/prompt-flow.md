# Prompt Flow — Mapa Completo de Prompts

Referencia de cada prompt del sistema: su origen, quién lo consume, qué placeholders usa y qué retorna.
Leer este documento antes de modificar cualquier prompt, agente o pipeline de IA.

---

## Dos tipos de prompt

```
HARDCODED (PHP)                          DB-SOURCED
─────────────────────────────────        ──────────────────────────────────────
app/Infrastructure/Ai/Prompts/           Tabla: ai_prompt_templates
  StyleGatekeeperPrompt                    key: player_profile_base
  StyleOptimizerPrompt                     key: vault_base
  ProfileOptimizerPrompt
  GatekeeperProfilePrompt (fallback)     Tabla: archetype_prompts
  ProfileTranslatorPrompt                  agent_type: gatekeeper
  VaultOptimizerPrompt (fallback)          agent_type: context_injection  ← inyección ContextOptimizer
  GatekeeperPrompt (acción narrativa)      agent_type: optimizer          ← fallback legacy
                                           agent_type: player_profile
                                           agent_type: vault

                                         Tabla: archetype_entity_types
                                           columna: system_prompt
```

**Regla general:** Los prompts hardcoded son el fallback de seguridad.
Los prompts en DB permiten personalización por archetype sin tocar código.

---

## Pipeline 1 — Registro de jugador (GatekeeperProfile)

**Trigger:** El jugador envía su ficha por Discord (`/registro` o modal).

```
Texto libre del jugador
        │
        ▼
[ProfileTemplateParser]  ← SIN LLM (solo regex)
  Extrae: age, nationality, experience_level, schedule,
          verbosity, red_lines, yellow_lines, affinities, raw_profile
        │
        ├── ¿isComplete()? ──NO──▶ [AI completion — GatekeeperProfileAgent]
        │                            Prompt:  archetype_prompts.gatekeeper  ← DB, por archetype
        │                            Fallback: GatekeeperProfilePrompt::getFallbackPrompt()  ← PHP
        │                            Placeholders: {partial_json_payload}, {raw_text_payload}
        │                            Input:   JSON parcial + texto original
        │                            Output:  JSON completo con los campos faltantes
        │                            Temp: 0.1 | Tokens: 600
        │
        ▼
[ProfileTranslatorAgent]  ← SIEMPRE corre
  Prompt:  ProfileTranslatorPrompt::getPrompt()  ← PHP hardcoded
  Input:   { red_lines[], yellow_lines[], preferences[] }
  Output:  Los mismos arrays traducidos al inglés
  Temp: 0.1 | Tokens: 600
        │
        ▼
PlayerArchetypeProfile guardado en DB
  positive_prefs ← affinities
  red_lines, yellow_lines, raw_profile, schedule, metadata
        │
        ├──▶ NormalizePlayerTagsJob
        └──▶ IndexPlayerStyleJob
```

**Prompts involucrados:**
| Prompt | Origen | Modificable sin deploy |
|--------|--------|----------------------|
| `GatekeeperProfilePrompt` | PHP (fallback) | No |
| `archetype_prompts.gatekeeper` | DB por archetype | **Sí — Filament** |
| `ProfileTranslatorPrompt` | PHP (siempre) | No |

---

## Pipeline 2 — Indexación de avatar: ContextOptimizer (nuevo, preferido)

**Trigger:** `IndexAvatarJob` — cuando un avatar tiene `entityType` con `system_prompt` configurado.

```
Avatar.content_raw (JSON con campos del formulario)
        │
        ▼
[EntityTypePromptBuilderService]
  Lee:    archetype_entity_types.system_prompt  ← DB por entity_type
  Filtra: ArchetypeMutator donde storage_mode IN (semantic, both)
          y context = entityType.getMutatorContext()
  Builds prompt reemplazando:
    {context_data_json}          ← campos semánticos del Context Entity (de los mutadores)
    {archetype_prompt_injection} ← archetype_prompts.optimizer  ← DB por archetype
    {vault_context}              ← Vault.name + Vault.description (si existe)
        │
        ▼ Prompt final (~5000 chars)
        │
[ContextOptimizerAgent]
  System message: forzado por código — "RAW JSON ONLY, optimized_text_en + semantic_tag_query"
  User message:   prompt construido arriba
  Output: { optimized_text_en: string, semantic_tag_query: string }
  Temp: 0.1 | Tokens: 800
        │
        ├──▶ Avatar.optimized_text_en guardado
        ├──▶ Avatar.semantic_tag_query guardado
        └──▶ NormalizeAvatarTagsJob + Embedding + Qdrant upsert
```

**Prompts involucrados:**
| Prompt | Origen | Qué contiene | Modificable sin deploy |
|--------|--------|-------------|----------------------|
| `archetype_entity_types.system_prompt` | DB por entity_type | Estructura fija: rol del LLM, RAW JSON ONLY, NO NEGATIVE LOGIC, EXPECTED JSON SCHEMA, los 3 placeholders | **Sí — Filament** |
| `archetype_prompts.context_injection` (injection) | DB por archetype | Solo reglas de dominio: terminología, dimensiones a enfatizar. SIN instrucciones de output | **Sí — Filament** |
| `archetype_prompts.optimizer` (fallback legacy) | DB por archetype | Fallback si no existe `context_injection` — WARNING en logs | **Sí — Filament** |
| System message de `ContextOptimizerAgent` | PHP hardcoded | Fuerza JSON independientemente del system_prompt | No |

**Condición de activación:** `$entityType && filled($entityType->system_prompt) && filled($avatar->content_raw)`
Si no se cumple → cae al Pipeline 3 (StyleOptimizer).

---

## Pipeline 3 — Indexación de avatar: StyleOptimizer (legacy, fallback)

**Trigger:** `IndexAvatarJob` cuando el avatar NO tiene entity_type con system_prompt.

```
Avatar bullets + perfil + vault (texto libre)
        │
        ▼
[StyleOptimizerAgent — Paso 1: Gatekeeper]
  Prompt:  StyleGatekeeperPrompt::getPrompt()  ← PHP hardcoded
  Input:   Texto libre del avatar
  Output:  { positives[], red_lines[], yellow_lines[] }
  Temp: 0.0 | Tokens: 800
        │
        ▼ Solo positives[]
        │
[StyleOptimizerAgent — Paso 2: Optimizer]
  Prompt:  resolveOptimizerPrompt() — lógica de resolución:
           1. archetype_prompts.optimizer   ← DB, si existe → usa este completo
           2. ai_prompt_templates.player_profile_base  ← DB (fallback: StyleOptimizerPrompt PHP)
              con {archetype_prompt_injection} ← archetype_prompts.player_profile ← DB
  Input:   JSON de positives[]
  Output:  Párrafo semántico denso (texto plano)
  Temp: 0.1 | Tokens: 600
        │
        ▼
Embedding + Qdrant upsert
```

**Prompts involucrados:**
| Prompt | Origen | Modificable sin deploy |
|--------|--------|----------------------|
| `StyleGatekeeperPrompt` | PHP hardcoded | No |
| `archetype_prompts.optimizer` | DB por archetype (si existe, reemplaza todo) | **Sí — Filament** |
| `ai_prompt_templates.player_profile_base` | DB (fallback: `StyleOptimizerPrompt` PHP) | **Sí — DB directo** |
| `archetype_prompts.player_profile` (injection) | DB por archetype | **Sí — Filament** |

---

## Pipeline 4 — Optimización de perfil de jugador (PlayerProfile)

**Trigger:** `IndexPlayerStyleJob` después del registro.

```
PlayerArchetypeProfile.positive_prefs + raw_profile
        │
        ▼
[OptimizerProfileAgent]
  Prompt:  resolveSystemPrompt() — lógica de resolución:
           1. archetype_prompts.optimizer  ← DB, si existe → usa este completo (legacy)
           2. ai_prompt_templates.player_profile_base  ← DB (fallback: ProfileOptimizerPrompt PHP)
              con {archetype_prompt_injection} ← archetype_prompts.player_profile ← DB
              con {user_soft_data_json}  ← datos del jugador (si el template lo tiene)
  Output:  { optimized_text_en, semantic_tag_query }  ← o texto plano en legacy
  Temp: 0.1 | Tokens: 600
        │
        ▼
PlayerArchetypeProfile.optimized_text + semantic_tag_query
Embedding + Qdrant upsert (players_profiles)
```

**Prompts involucrados:**
| Prompt | Origen | Modificable sin deploy |
|--------|--------|----------------------|
| `archetype_prompts.optimizer` | DB por archetype (legacy, si existe) | **Sí — Filament** |
| `ai_prompt_templates.player_profile_base` | DB (fallback: `ProfileOptimizerPrompt` PHP) | **Sí — DB directo** |
| `archetype_prompts.player_profile` (injection) | DB por archetype | **Sí — Filament** |

---

## Pipeline 5 — Optimización de Vault

**Trigger:** `IndexVaultJob` cuando se crea o actualiza un Vault.

```
Vault { name, description }
        │
        ▼
[VaultOptimizerAgent]
  Prompt:  resolveSystemPrompt():
           ai_prompt_templates.vault_base  ← DB (fallback: VaultOptimizerPrompt PHP)
           con {archetype_prompt_injection} ← archetype_prompts.vault ← DB por archetype
  Input:   { "name": "...", "description": "..." }
  Output:  { name_es, name_en, optimized_text_en, semantic_tag_query }
  Temp: 0.2 | Tokens: 800
        │
        ▼
Vault.optimized_text_en + semantic_tag_query + name_es + name_en
Embedding + Qdrant upsert
```

**Prompts involucrados:**
| Prompt | Origen | Modificable sin deploy |
|--------|--------|----------------------|
| `ai_prompt_templates.vault_base` | DB (fallback: `VaultOptimizerPrompt` PHP) | **Sí — DB directo** |
| `archetype_prompts.vault` (injection) | DB por archetype | **Sí — Filament** |

---

## Pipeline 6 — Validación de acción narrativa (GatekeeperAgent)

**Trigger:** Turno de rol activo — el jugador envía una acción.

```
Acción del jugador (mensaje Discord)
        │
        ▼
[GatekeeperAgent]
  Prompt:  GatekeeperPrompt::buildInstruction(...)  ← PHP hardcoded, totalmente dinámico
  Parámetros:
    {$vaultSynopsis}   ← Vault.description
    {$locationName}    ← Location.name
    {$locationDesc}    ← Location.description
    {$tagsString}      ← Estado activo del personaje (tags de runtime)
    {$playerConcept}   ← char_class del personaje
  Input:  Acción del jugador
  Output: { accepted: bool, reason: string, penalty_points: int }
  Temp: 0.1 | Tokens: 500
```

**Este pipeline es 100% hardcoded.** No usa DB para prompts. Los valores dinámicos vienen de parámetros del método, no de placeholders.

---

## Contrato de placeholders

> **Fuente de verdad en código:** `app/Infrastructure/Ai/PromptPlaceholder.php`
> Cada nombre canónico está definido ahí como enum. No escribir los strings a mano.

Cada placeholder tiene **exactamente una fuente**. Usar un nombre distinto rompe silenciosamente:
el servicio no lo encuentra, el LLM recibe el placeholder literal sin datos, y alucina contenido.

| Enum case | Valor exacto | Fuente | Usado en | Obligatorio |
|-----------|-------------|--------|----------|-------------|
| `ContextData` | `{context_data_json}` | `ArchetypeMutator` (storage=semantic\|both) del entity_type | `archetype_entity_types.system_prompt` — entidades: avatar, libro, juego | Al menos uno de los dos |
| `UserSoftData` | `{user_soft_data_json}` | `ArchetypeMutator` (storage=semantic\|both) del entity_type | `archetype_entity_types.system_prompt` — activities y pipeline de perfil | Al menos uno de los dos |
| `ArchetypeInjection` | `{archetype_prompt_injection}` | `archetype_prompts.optimizer` | `archetype_entity_types.system_prompt` | **Sí siempre** |
| `VaultContext` | `{vault_context}` | `Vault.name + Vault.description` | `archetype_entity_types.system_prompt` (solo activity) | No |
| `PartialJson` | `{partial_json_payload}` | JSON parcial del perfil | `archetype_prompts.gatekeeper` | Sí (en gatekeeper) |
| `RawText` | `{raw_text_payload}` | Texto libre del usuario | `archetype_prompts.gatekeeper` | Sí (en gatekeeper) |

> **Regla de datos:** Todo entity_type debe tener **al menos uno** de `{context_data_json}` o `{user_soft_data_json}`.
> Ambos pueden coexistir — una activity de gaming usa `{context_data_json}` para describir el juego
> y `{user_soft_data_json}` para las preferencias del jugador en esa sesión.
> Nunca usar `{context_data_json}` solo para entidades inmutables (avatar, libro, juego): su vector
> debe describir la entidad, no a quien la creó.

### Validación automática en `EntityTypePromptBuilderService`

`buildPrompt()` ejecuta dos verificaciones en cada llamada y las reporta en los logs:

**Pre-build** — detecta `{archetype_prompt_injection}` ausente, o ausencia total de placeholder de datos:
```
ERROR [EntityTypePromptBuilderService@buildPrompt] Placeholder requerido ausente en system_prompt
  entity_type_id: xxx
  missing_placeholder: {archetype_prompt_injection}
  hint: Ver docs/prompt-flow.md — sección Contrato de placeholders

ERROR [EntityTypePromptBuilderService@buildPrompt] Ningún placeholder de datos encontrado en system_prompt
  entity_type_id: xxx
  expected_one_of: ["{context_data_json}", "{user_soft_data_json}"]
  hint: Ver docs/prompt-flow.md — sección Contrato de placeholders
```

**Post-build** — detecta placeholders que no fueron reemplazados (posible typo):
```
WARNING [EntityTypePromptBuilderService@buildPrompt] Placeholder no reemplazado — posible typo en system_prompt
  entity_type_id: xxx
  unreplaced_placeholder: {user_soft_data_json}
  hint: Ver docs/prompt-flow.md — sección Contrato de placeholders
```

Ambos errores apuntan a este documento para resolución rápida.

---

## Qué va en cada prompt de DB

### `archetype_entity_types.system_prompt`
- Rol del LLM específico del dominio
- Regla `RAW JSON ONLY` (obligatorio)
- Regla `NO NEGATIVE LOGIC` (obligatorio)
- `EXPECTED JSON SCHEMA` con `optimized_text_en` y `semantic_tag_query` (obligatorio)
- Placeholders `{user_soft_data_json}` y `{archetype_prompt_injection}` (obligatorios)
- Placeholder `{vault_context}` (solo si entity = activity)
- Ejemplos de input/output (recomendado)

### `archetype_prompts.optimizer` (injection para ContextOptimizer)
- Terminología de dominio a usar
- Dimensiones semánticas a enfatizar
- Reglas de contenido específicas del archetype
- **NO incluir instrucciones de output format** (lo maneja el system_prompt del entity_type)

### `archetype_prompts.gatekeeper`
- Schema JSON de campos a extraer
- Qué valores son válidos para cada campo
- Reglas: JSON only, no modificar campos existentes, traducir al inglés
- Placeholders `{partial_json_payload}` y `{raw_text_payload}`

### `archetype_prompts.player_profile` (injection para StyleOptimizer/OptimizerProfile)
- Instrucciones de dominio para la optimización del perfil del jugador
- Se inyecta en `player_profile_base` via `{archetype_prompt_injection}`

### `archetype_prompts.vault`
- Instrucciones de dominio para la optimización del Vault
- Se inyecta en `vault_base` via `{archetype_prompt_injection}`

### `ai_prompt_templates.player_profile_base`
- Template base genérico para perfiles
- Contiene las reglas de optimización universales
- Contiene `{archetype_prompt_injection}` para personalización por archetype
- Los agentes lo usan como fallback si no hay prompt legacy en `archetype_prompts.optimizer`

### `ai_prompt_templates.vault_base`
- Template base para optimización de Vaults
- Contiene `{archetype_prompt_injection}` para personalización por archetype

---

## Lógica de resolución de prompts (StyleOptimizer / OptimizerProfile)

Ambos agentes siguen la misma prioridad:

```
1. ¿Existe archetype_prompts.optimizer para este archetype?
   SÍ → Usar ese prompt completo como system message (modo legacy)
   NO → Continuar

2. Cargar ai_prompt_templates.player_profile_base
   (fallback hardcoded: StyleOptimizerPrompt o ProfileOptimizerPrompt)

3. ¿Existe archetype_prompts.player_profile para este archetype?
   SÍ → Inyectar en {archetype_prompt_injection}
   NO → {archetype_prompt_injection} queda vacío
```

**Implicación:** Si existe `archetype_prompts.optimizer`, los agentes legacy lo usan directamente
y **nunca llegan al template base ni a la injection de `player_profile`**.
Esto hace que `optimizer` sea un override total del pipeline legacy.

---

---

## Pipeline 7 — Entrevista de texto: MUDRAIS Weaver

**Trigger:** `/interview` en Discord (sin opción `respuesta` para iniciar; con `respuesta` para cada turno).
**Queue:** `default` — `ProcessInterviewTurnJob`.

```
/interview (turno 0, sin respuesta)
        │
[InterviewerAgent.resolveFields(archetypeId, 'registration')]
  Lee: ArchetypeMutator del arquetipo activo (registration context)
  Output: array de campos con field_key, field_label, is_required, hint
        │
        ▼ Genera pregunta de apertura
  Prompt: ArchetypePrompt(interview_opening) | fallback: i18n discord.interview_opening_question
        │
/interview respuesta:"texto del jugador" (turno N)
        │
        ▼
[InterviewGatekeeperAgent]
  Prompt:  ArchetypePrompt('interview_gatekeeper')
           | AiPromptTemplate('interview_gatekeeper')
           | InterviewGatekeeperPrompt::getFallbackPrompt()  ← PHP
  Input:   { respuesta_del_jugador, campos_pendientes_json, archetype_context }
  Output:  { english_text: string, extracted: { field_key: value } }
  Temp: 0.1 | Tokens: 600
        │
        ▼
[InterviewOptimizerAgent]
  Reutiliza lógica de resolución de StyleOptimizer/OptimizerProfile:
    1. ArchetypePrompt('optimizer') si existe → override completo
    2. AiPromptTemplate('interview_optimizer') con {archetype_prompt_injection} ← player_profile
  Input:   campos extraídos no vacíos
  Output:  campos normalizados (inglés, limpios)
  Temp: 0.1 | Tokens: 400
        │
        ▼
[RegistrationAnalystAgent]  — PHP puro, sin LLM
  campo completo: mb_strlen(trim(value)) >= 3
  Output: { is_complete: bool, missing_required: [], missing_optional: [], complete_fields: [] }
        │
  ┌── is_complete? ──NO──▶ [InterviewerAgent]
  │                          Prompt: ArchetypePrompt('interviewer')
  │                                  | AiPromptTemplate('interviewer_question')
  │                                  | InterviewerPrompt::getDefaultPrompt()  ← PHP
  │                          Input:  { missing_fields, all_fields, conversation_history }
  │                          Output: siguiente pregunta (una sola)
  │                          Temp: 0.7 | Tokens: 200
  │
  └── is_complete? ──SÍ──▶ Embed de confirmación → btn_interview_accept
                             ProcessInterviewAcceptJob
                               → setea registro_step1_{discordId} + registro_archetype_{discordId}
                               → ProcessRegistroStep2Job → Pipeline 1 + Pipelines 2/3/4
```

**Prompts involucrados:**

| Prompt | Origen | Modificable sin deploy |
|---|---|---|
| `ArchetypePrompt(interview_gatekeeper)` | DB por archetype | **Sí — Filament** |
| `AiPromptTemplate(interview_gatekeeper)` | DB global (fallback) | **Sí — DB directo** |
| `AiPromptTemplate(interview_optimizer)` | DB global | **Sí — DB directo** |
| `ArchetypePrompt(interviewer)` | DB por archetype | **Sí — Filament** |
| `AiPromptTemplate(interviewer_question)` | DB global (fallback) | **Sí — DB directo** |
| `ArchetypePrompt(interview_opening)` | DB por archetype | **Sí — Filament** |

---

## Pipeline 8 — Entrevista de voz: MUDRAIS Voice

**Trigger:** `/voice-interview` (bot Gamma) → señal Redis → `voice-bridge` (Node.js).
**Queue:** `voice` — `ProcessVoiceInterviewTurnJob`.

```
/voice-interview (Discord Gamma bot)
        │
[DiscordController@handleVoiceInterviewCommand]
  → VoiceInterviewSessionManager::pushStartCommand() en Redis
  → type:5 deferred ephemeral

        ──── voice-bridge (Node.js) ────────────────────────────

GET /api/voice/pending-start  (polling cada 2s)
  → LPOP atómico — consume señal

POST /api/voice/session/start
        │
[VoiceInterviewController@startSession]
  → Carga archetypes incompletos del jugador (cola)
  → Pregunta de apertura del primer archetype
  → VoiceTextTranslator::toEnglish(opening_question)
  → Retorna { session_id, opening_question_en, archetype_id }

[voice-bridge] sintetiza opening_question_en (Speechmatics TTS en inglés)
               escucha audio del usuario en el canal de voz

        ──── por cada respuesta de voz ─────────────────────────

[Speechmatics API] transcripción en tiempo real
  Audio → raw transcript string

POST /api/voice/transcription { session_id, transcript, discord_id }
        │
[VoiceInterviewController@handleTranscription]
  │
  ├── Despacha ProcessVoiceInterviewTurnJob (queue 'voice', background)
  │
  └── StreamedResponse: TalkatorAgent.respond(transcript, 'en', callback)
        Prompt: AiPromptTemplate('talkator') | TalkatorPrompt  ← PHP fallback
        Output: respuesta conversacional en streaming (inglés)
        → voice-bridge reproduce audio mientras el job procesa

        ──── ProcessVoiceInterviewTurnJob (background) ─────────

[VoiceInterviewTurnAgent]  — UNA sola llamada LLM
  Prompt: AiPromptTemplate('voice_interview_turn') | VoiceInterviewTurnPrompt  ← PHP
  Input:  { transcript, all_fields, already_extracted, conversation_history, last_question }
  Output: { response_type: 'answer'|'off_topic'|'spam', extracted: {}, next_question: string|null }
  Temp: 0.2 | Tokens: 600

  response_type != 'answer' → pushNextQuestion(voice_off_topic_redirect, inglés)

[VoiceAnalystAgent]  — PHP puro, sin LLM
  Evalúa extracted + requiredFieldKeys + optionalFieldKeys
  Output: { is_complete, missing_required, missing_optional }

  is_complete? → completeCurrentArchetype() → advanceToNextArchetype()
    hasNext? → resolveOpeningQuestion() → pushNextQuestion (inglés)
    !hasNext? → pushNextQuestion(voice_session_complete)

  !is_complete? → pushNextQuestion(turnResult.next_question, ya en inglés)

GET /api/voice/next-question/{sessionId}  (polling cada 500ms)
  → LPOP atómico
  → voice-bridge reproduce pregunta en el canal de voz
```

**Nota sobre idiomas:** Todo lo que sale del pipeline hacia el TTS va en inglés (Speechmatics solo
tiene voces en inglés). `VoiceTextTranslator::toEnglish()` traduce si el locale es `es`.
Los campos extraídos se guardan en inglés por consistencia con el pipeline de vectorización.

**Prompts involucrados:**

| Prompt | Origen | Modificable sin deploy |
|---|---|---|
| `AiPromptTemplate('voice_interview_turn')` | DB global | **Sí — DB directo** |
| `VoiceInterviewTurnPrompt` (PHP) | Hardcoded | No |
| `AiPromptTemplate('talkator')` | DB global | **Sí — DB directo** |
| `TalkatorPrompt` (PHP) | Hardcoded | No |

---

## Problemas conocidos

| Problema | Causa | Fix |
|----------|-------|-----|
| `ContextOptimizerAgent` recibe datos pero el LLM alucina | El `system_prompt` usa un placeholder inventado (ej: `{book_soft_data_json}`) que el servicio no reemplaza | Usar solo `{context_data_json}` o `{user_soft_data_json}` |
| El LLM del ContextOptimizer devuelve párrafo en vez de JSON | El `archetype_prompts.optimizer` contiene instrucciones de "flowing paragraph" que contradicen el JSON | El optimizer injection solo debe tener reglas de dominio |
| Prompt silenciosamente vacío | Placeholder con typo — el servicio no lo encuentra y lo deja literal | Ver sección "Contrato de placeholders" |
