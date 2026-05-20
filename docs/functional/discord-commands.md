# Comandos Discord — MUDRAIS

Referencia completa de todos los slash commands disponibles en el bot de MUDRAIS, incluyendo opciones, flujos de interacción y comportamiento esperado.

---

## Índice

1. [/registro](#1-registro)
2. [/ficha](#2-ficha)
3. [/interview — MUDRAIS Weaver](#3-interview--mudrais-weaver)
4. [/voice-interview — MUDRAIS Voice](#4-voice-interview--mudrais-voice)
5. [/create_vault](#5-create_vault)
6. [/create](#6-create)
7. [/actividad crear](#7-actividad-crear)
8. [/buscar-actividad](#8-buscar-actividad)
9. [/buscar-partner](#9-buscar-partner)
10. [/status](#10-status)
11. [Registro de Comandos en Discord](#11-registro-de-comandos-en-discord)

---

## 1. `/registro`

**Descripción:** Punto de entrada para nuevos jugadores y edición de ficha para existentes. Máquina de estados que evalúa la situación del jugador antes de abrir cualquier modal.

**Opciones:** ninguna

**Flujo según estado del jugador:**

| Estado | Respuesta |
|---|---|
| Jugador nuevo | Embed verde + botones de género para iniciar registro |
| Player existe, sin perfil de arquetipo | Embed con botón para completar perfil de arquetipo (gratuito) |
| Player existe, tutorial incompleto | Error efímero — debe completar el tutorial primero |
| Player existe, saldo insuficiente | Error efímero con coste de edición |
| Player completo | Embed azul con coste de edición + botón para abrir modal |

**Registrar en Discord Developer Portal:**
```json
{
  "name": "registro",
  "description": "Registra o edita tu ficha de jugador en MUDRAIS"
}
```

---

## 2. `/ficha`

**Descripción:** Abre un modal para pegar la ficha de identidad MUDRAIS en texto libre. El bot la procesa con IA y extrae los campos relevantes.

**Opciones:** ninguna

**Flujo:**
1. Responde inmediatamente con modal (type:9)
2. El jugador pega su ficha de texto
3. `ProcessFichaModalJob` analiza el texto con LLM y actualiza el perfil

**Registrar en Discord Developer Portal:**
```json
{
  "name": "ficha",
  "description": "Sube tu ficha de identidad MUDRAIS en texto libre"
}
```

---

## 3. `/interview` — MUDRAIS Weaver

**Descripción:** Ruta conversacional alternativa al modal de `/registro`. Un agente de IA guía al jugador por chat en Discord, extrayendo los campos del arquetipo mediante preguntas naturales en lugar de un formulario estático. Es la implementación de **MUDRAIS Weaver**.

**Bot:** Alpha (bot principal)

**Opciones:**

| Nombre | Tipo | Requerido | Descripción |
|---|---|---|---|
| `respuesta` | String | No | Respuesta del jugador a la pregunta actual. Omitir para iniciar/reanudar la sesión. |
| `reiniciar` | Boolean | No | Fuerza el reinicio de la sesión de entrevista desde cero. |

**Flujo completo:**

```
/interview (sin opciones)
        │
        ▼ type:5 deferred
ProcessInterviewTurnJob(turn=0, answer='', queue='default')
        │
[InterviewerAgent.resolveFields()]  — lee ArchetypeMutator del arquetipo activo
        │
        ▼ Si turn=0
Genera pregunta de apertura
  → ArchetypePrompt (agent_type='interview_opening') | fallback: i18n discord.interview_opening_question
        │
        ▼ El jugador responde con /interview respuesta:"..."
ProcessInterviewTurnJob(turn=N, answer=texto, queue='default')
        │
[InterviewGatekeeperAgent]
  Prompt: AiPromptTemplate('interview_gatekeeper') | ArchetypePrompt('interview_gatekeeper') | PHP fallback
  Input:  respuesta del jugador + campos pendientes
  Output: { english_text, extracted: {field_key: value} }
        │
[InterviewOptimizerAgent]
  Reutiliza prompts optimizer/player_profile del arquetipo
  Input:  campos extraídos no vacíos
  Output: campos normalizados
        │
[RegistrationAnalystAgent]  — PHP puro, sin LLM
  campo completo = mb_strlen(trim(value)) >= 3
  Output: { is_complete, missing_required, complete_fields }
        │
  ┌── is_complete? ──NO──▶ [InterviewerAgent]
  │                          Prompt: AiPromptTemplate('interviewer_question')
  │                          Output: siguiente pregunta
  │
  └── is_complete? ──SÍ──▶ Embed de confirmación
                             Botones: btn_interview_accept | btn_interview_retry | btn_interview_cancel
                             │
                             ▼ btn_interview_accept
                           ProcessInterviewAcceptJob
                             → setea registro_step1_{discordId} + registro_archetype_{discordId}
                             → despacha ProcessRegistroStep2Job (pipeline completo de vectorización)
```

**Estado en caché (Redis, TTL 30 min):**
```json
{
  "archetype_id": "uuid|null", "guild_id": "...", "username": "...", "locale": "es",
  "turn": 2, "status": "in_progress|awaiting_confirmation",
  "extracted_fields": { "preferences": "...", "style": "..." },
  "required_field_keys": [...], "missing_required_keys": [...],
  "conversation_history": [{"role": "assistant|user", "content": "..."}]
}
```
Clave: `interview_state_{discordId}` — MAX_TURNS = 10.

**Personalización por arquetipo** (Filament → Archetype → Prompts IA):

| `agent_type` | Propósito |
|---|---|
| `interviewer` | System prompt / personalidad del agente |
| `interview_opening` | Pregunta de apertura personalizada (turno 0) |
| `interview_gatekeeper` | Extracción + traducción por arquetipo |

**Archivos clave:**
- `app/Infrastructure/Ai/Agents/InterviewerAgent.php`
- `app/Infrastructure/Ai/Agents/InterviewGatekeeperAgent.php`
- `app/Infrastructure/Ai/Agents/InterviewOptimizerAgent.php`
- `app/Jobs/Discord/ProcessInterviewTurnJob.php`
- `app/Jobs/Discord/ProcessInterviewAcceptJob.php`

**Registrar en Discord Developer Portal:**
```json
{
  "name": "interview",
  "description": "Fill your archetype profile through an AI-guided conversation.",
  "name_localizations": { "es-ES": "entrevista" },
  "description_localizations": { "es-ES": "Completa tu ficha de arquetipo mediante una conversación guiada por IA." },
  "type": 1,
  "options": [
    {
      "name": "respuesta",
      "description": "Your answer to the current interview question",
      "name_localizations": { "es-ES": "respuesta" },
      "type": 3,
      "required": false
    },
    {
      "name": "reiniciar",
      "description": "Force-restart your interview session from scratch.",
      "name_localizations": { "es-ES": "reiniciar" },
      "type": 5,
      "required": false
    }
  ]
}
```

---

## 4. `/voice-interview` — MUDRAIS Voice

**Descripción:** Inicia una sesión de entrevista de voz. El bot Gamma conecta al usuario a un canal de voz; el microservicio `voice-bridge` transcribe el audio en tiempo real con Speechmatics y lo entrega al pipeline de Weaver en Laravel. El usuario nunca toca el teclado.

**Bot:** Gamma (voice gateway — `discord:gateway gamma`)

**Opciones:** ninguna

**Flujo completo:**

```
/voice-interview (Gamma bot)
        │
        ▼ type:5 deferred ephemeral
[DiscordController@handleVoiceInterviewCommand]
  → VoiceInterviewSessionManager::pushStartCommand() en Redis
  → type:5 ephemeral — Discord muestra spinner

        ──── microservicio voice-bridge (Node.js) ────

[voice-bridge polls GET /api/voice/pending-start cada 2s]
  → Consume señal LPOP atómica de Redis
  → Llama POST /api/voice/session/start
        │
[VoiceInterviewController@startSession]
  → Construye cola de archetypes incompletos del jugador
  → Genera pregunta de apertura (traduce a inglés para TTS)
  → Retorna { session_id, opening_question_en, archetype_id }
        │
        ▼
[voice-bridge] sintetiza opening_question_en a voz (Speechmatics TTS)
               escucha audio del canal de voz del usuario

        ── por cada respuesta del usuario ──

[voice-bridge] transcribe audio → POST /api/voice/transcription
  { session_id, transcript, discord_id }
        │
[VoiceInterviewController@handleTranscription]
  1. Despacha ProcessVoiceInterviewTurnJob (queue 'voice') — background
  2. Devuelve StreamedResponse con TalkatorAgent (respuesta conversacional en inglés)
        │
  ┌── background job ──────────────────────────────────────────┐
  │ ProcessVoiceInterviewTurnJob                               │
  │                                                            │
  │ [VoiceInterviewTurnAgent] — UNA sola llamada LLM          │
  │   Extrae campos del transcript + genera siguiente pregunta │
  │   Output: { response_type, extracted, next_question }      │
  │                                                            │
  │ [VoiceAnalystAgent] — PHP puro                            │
  │   Evalúa completitud del archetype actual                  │
  │                                                            │
  │   is_complete? → advanceToNextArchetype()                  │
  │   todos completos? → pushNextQuestion('session_complete')  │
  │   else → pushNextQuestion(next_question)                   │
  └────────────────────────────────────────────────────────────┘
        │
[voice-bridge polls GET /api/voice/next-question/{sessionId} cada 500ms]
  → Consume pregunta (LPOP atómico)
  → Sintetiza a voz y la reproduce en el canal
```

**Polling endpoints (voice-bridge ↔ Laravel):**

| Endpoint | Método | Propósito |
|---|---|---|
| `/api/voice/pending-start` | GET | Señal de inicio de sesión (LPOP Redis) |
| `/api/voice/session/start` | POST | Crea sesión, devuelve opening question |
| `/api/voice/transcription` | POST | Entrega transcript, retorna TalkatorAgent streaming |
| `/api/voice/next-question/{sessionId}` | GET | Siguiente pregunta del pipeline (LPOP Redis) |

**Autenticación:** middleware `VerifyVoiceBridgeSecret` — header `X-Voice-Bridge-Secret`.

**Estado de sesión (Redis, VoiceInterviewSessionManager):**
- Cola de archetypes incompletos del jugador
- Campos extraídos acumulativos por archetype
- Historial de conversación (para contexto del agente)
- Clave de siguiente pregunta pendiente: `voice_next_question_{sessionId}`

**Nota TTS:** Speechmatics solo tiene voces en inglés. Todas las respuestas del agente se traducen a inglés con `VoiceTextTranslator::toEnglish()` antes de ser sintetizadas. La sesión interna puede correr en `es` o `en` según el locale del jugador.

**Archivos clave:**
- `app/Http/Controllers/Api/Voice/VoiceInterviewController.php`
- `app/Jobs/Voice/ProcessVoiceInterviewTurnJob.php`
- `app/Infrastructure/Ai/Agents/VoiceInterviewTurnAgent.php`
- `app/Infrastructure/Ai/Agents/VoiceAnalystAgent.php`
- `app/Infrastructure/Ai/Agents/TalkatorAgent.php`
- `app/Services/Voice/VoiceInterviewSessionManager.php`
- `app/Services/Voice/VoiceTextTranslator.php`
- `app/Http/Middleware/VerifyVoiceBridgeSecret.php`
- `voice-bridge/` — microservicio Node.js

---

## 5. `/create_vault`

**Descripción:** Crea un nuevo Vault (mundo/servidor de juego) dentro del arquetipo seleccionado. Genera automáticamente los canales de Discord correspondientes.

**Opciones:**

| Nombre | Tipo | Requerido | Autocomplete | Descripción |
|---|---|---|---|---|
| `archetype` | String | Sí | Sí | Arquetipo al que pertenece el Vault |

**Flujo:**
1. Autocomplete devuelve lista de arquetipos disponibles
2. Al ejecutar → modal paginado de creación de Vault (máx. 5 campos por página)
3. `ProcessVaultOnboardingJob` crea: canal de texto del Vault, foro de contextos, foro de actividades
4. El Vault queda pendiente de aprobación por un admin

**Registrar en Discord Developer Portal:**
```json
{
  "name": "create_vault",
  "description": "Crea un nuevo Vault dentro de un arquetipo",
  "options": [
    {
      "name": "archetype",
      "description": "Arquetipo al que pertenecerá el Vault",
      "type": 3,
      "required": true,
      "autocomplete": true
    }
  ]
}
```

---

## 6. `/create`

**Descripción:** Crea un contexto (personaje, locación, ítem u otro tipo de entidad) dentro del Vault activo del canal. Debe ejecutarse desde el canal principal del Vault.

**Opciones:**

| Nombre | Tipo | Requerido | Autocomplete | Descripción |
|---|---|---|---|---|
| `type` | String | Sí | Sí | Tipo de entidad a crear (filtrado por arquetipo del canal) |

**Flujo:**
1. Autocomplete devuelve tipos de entidad del arquetipo del Vault actual
2. Al ejecutar → embed con lista de entidades existentes + botón "Crear →"
3. Al pulsar el botón → modal paginado con los campos del tipo seleccionado
4. `ProcessCreateContextJob` crea la entidad en BD y la indexa en Qdrant
5. **Auto-vinculación:** el creador queda automáticamente vinculado a su entidad en `avatar_profile` (disponible para usar en `/actividad crear`)

> **Nota:** Después de crear una entidad de tipo `avatar`, aparece el botón "Configurar Atributos ⚙️" para completar el perfil de arquetipo (Step 2).

**Registrar en Discord Developer Portal:**
```json
{
  "name": "create",
  "description": "Crea un personaje, locación u otro contexto en el Vault activo",
  "options": [
    {
      "name": "type",
      "description": "Tipo de entidad a crear",
      "type": 3,
      "required": true,
      "autocomplete": true
    }
  ]
}
```

---

## 7. `/actividad crear`

**Descripción:** Publica una búsqueda de grupo (LFG) vinculando hasta dos contextos del Vault (personaje, locación, etc.) y añadiendo un título y descripción libre. La actividad se indexa en Qdrant con vectores semánticos independientes por contexto para el matchmaking.

**Opciones:**

| Nombre | Tipo | Requerido | Autocomplete | Descripción |
|---|---|---|---|---|
| `contexto_principal` | String | Sí | Sí | Primer contexto del jugador (personaje, locación…) |
| `contexto_secundario` | String | No | Sí | Segundo contexto opcional (puede ser de tipo diferente) |

> El autocomplete solo muestra entidades que el jugador ha **aceptado usar explícitamente** (tabla `avatar_profile`). Las entidades propias se vinculan automáticamente al crearlas con `/create`.

**Flujo:**
1. Autocomplete filtra entidades del jugador en el vault actual (`avatar_profile` + `vault_id`)
2. Al presionar Enter → modal inmediato (type:9) con dos campos:
   - **¿Qué estás buscando?** (texto corto, obligatorio, 5–100 chars)
   - **Contexto Extra** (párrafo libre, opcional — horarios, nivel, restricciones)
3. Al enviar el modal → `ProcessCreateActividadJob` (queue `default`):
   - Construye `activity_description` concatenando nombres de contextos + título + extra
   - Crea `Activity` en BD con `status = RECRUITING`
   - Guarda `ctx1_qdrant_id` / `ctx2_qdrant_id` en `content_raw` (para búsqueda multi-vector)
4. `IndexActivityJob` (queue `heavy`):
   - Genera vector `activity_vibe` (texto completo embebido)
   - Copia vectores `ctx1_context` y `ctx2_context` desde los puntos de los avatars en el hub
   - Auto-asigna canonical tags por similitud semántica (`searchTaxonomyTags`, threshold 0.72)
   - Upserta punto en `matchmaking_hub` con todos los vectores nombrados

**Vectores almacenados en `matchmaking_hub` por actividad:**

| Named vector | Origen | Peso en búsqueda |
|---|---|---|
| `activity_vibe` | Texto del Modal embebido | 10% |
| `ctx1_context` | Vector del contexto principal | 30% |
| `ctx2_context` | Vector del contexto secundario (si hay) | — |
| `player_style` | Vector del perfil del creador | 60% |
| `vault_setting` | Vector del lore/mundo del vault | — |

**Registrar en Discord Developer Portal:**
```json
{
  "name": "actividad",
  "description": "Gestiona actividades de búsqueda de grupo en el Vault",
  "options": [
    {
      "name": "crear",
      "description": "Publica una nueva búsqueda de grupo",
      "type": 1,
      "options": [
        {
          "name": "contexto_principal",
          "description": "Tu personaje o contexto principal para esta actividad",
          "type": 3,
          "required": true,
          "autocomplete": true
        },
        {
          "name": "contexto_secundario",
          "description": "Segundo contexto opcional (puede ser de tipo diferente)",
          "type": 3,
          "required": false,
          "autocomplete": true
        }
      ]
    }
  ]
}
```

---

## 8. `/buscar-actividad`

**Descripción:** Busca actividades compatibles en el Vault actual usando una firma de búsqueda multi-vector ponderada. No requiere haber publicado una actividad previamente — busca entre las de otros jugadores.

**Opciones:**

| Nombre | Tipo | Requerido | Autocomplete | Descripción |
|---|---|---|---|---|
| `texto` | String | No | No | Texto libre de búsqueda ("busco trama de vampiros") |
| `contexto` | String | No | Sí | Tu personaje/contexto para enriquecer la búsqueda |

**Flujo:**
1. Responde inmediatamente con type:5 (deferred — Discord muestra "pensando…")
2. `ProcessBuscarActividadJob` (queue `high`) construye la **firma de búsqueda**:

   | Vector de consulta | Origen | Peso base |
   |---|---|---|
   | `player_style` | Tu `player_style_vector` del perfil de arquetipo | 60% |
   | `ctx1_context` | Vector del avatar/contexto seleccionado | 30% |
   | `activity_vibe` | Embedding del texto libre introducido | 10% |

   > Los pesos se **normalizan automáticamente** si algún vector no está disponible (sin texto, sin contexto, etc.). Son configurables por arquetipo en `Archetype.search_weights`.

3. **Filtros duros** aplicados antes de buscar:
   - `archetype_id` = arquetipo del canal/vault
   - `status` = `RECRUITING` (solo actividades abiertas)

4. Corre hasta 3 llamadas `searchHub()` en paralelo (una por vector activo), acumula scores ponderados por `activity_id` y devuelve **Top 5** ordenados por score final.

5. Embed de resultados con: título, vault, mención al creador, contextos, texto extra y porcentaje de compatibilidad.

**Ejemplo de resultado:**

```
🎯 Actividades Compatibles

#1 — Busco tanque para mazmorra épica (82.3%)
🏰 Vault Oscuro · 👤 @JugadorX
🎭 Kira (personaje) · Mazmorra del Abismo (locación)
> Fines de semana a las 8pm, nivel 80+

#2 — Trama de vampiros lenta (71.4%)
...
```

**Registrar en Discord Developer Portal:**
```json
{
  "name": "buscar-actividad",
  "description": "Busca actividades compatibles con tu perfil en el Vault actual",
  "options": [
    {
      "name": "texto",
      "description": "Qué tipo de actividad buscas (opcional)",
      "type": 3,
      "required": false
    },
    {
      "name": "contexto",
      "description": "Tu personaje o contexto para afinar la búsqueda (opcional)",
      "type": 3,
      "required": false,
      "autocomplete": true
    }
  ]
}
```

---

## 9. `/buscar-partner`

**Descripción:** Busca jugadores compatibles (no actividades) usando el vector de perfil del jugador. Matchmaking de personas, no de actividades.

**Opciones:** ninguna

**Flujo:**
1. Deferred (type:5)
2. `ProcessBuscarJob` busca en `mudrais_profiles` o `players_profiles` según si hay perfil B2B de arquetipo
3. Devuelve Top 5 jugadores con score y tags en común

---

## 10. `/status`

**Descripción:** Muestra el estado actual del jugador: monedas, energía, perfil y estado del tutorial.

**Opciones:** ninguna

**Flujo:**
1. Deferred (type:5)
2. `ProcessStatusJob` consulta el player y devuelve embed con datos actuales

---

## 11. Registro de Comandos en Discord

Los comandos deben registrarse via la API de Discord o el Developer Portal. Para registrar todos de una vez con la CLI de Discord:

```bash
# Usando la REST API de Discord directamente
curl -X PUT \
  "https://discord.com/api/v10/applications/{APP_ID}/guilds/{GUILD_ID}/commands" \
  -H "Authorization: Bot {BOT_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '[...array con los JSON de cada comando...]'
```

> Los comandos con `autocomplete: true` requieren que el bot responda a eventos `APPLICATION_COMMAND_AUTOCOMPLETE` (type:4) dentro de 3 segundos. El endpoint `/api/discord/interactions` ya los maneja.

### Tabla resumen de interacciones

| Comando | Bot | Respuesta inicial | Job despachado | Queue |
|---|---|---|---|---|
| `/registro` | Alpha | type:4 embed | ninguno | — |
| `/ficha` | Alpha | type:9 modal | `ProcessFichaModalJob` | default |
| `/interview` | Alpha | type:5 deferred | `ProcessInterviewTurnJob` | default |
| `/voice-interview` | Gamma | type:5 deferred ephemeral | (señal Redis → voice-bridge) | voice |
| `/create_vault` | Alpha | type:9 modal | `ProcessVaultOnboardingJob` | default |
| `/create` | Alpha | type:4 embed + botón | `ProcessCreateContextJob` | default |
| `/actividad crear` | Alpha | type:9 modal | `ProcessCreateActividadJob` | default |
| `/buscar-actividad` | Alpha | type:5 deferred | `ProcessBuscarActividadJob` | high |
| `/buscar-partner` | Alpha | type:5 deferred | `ProcessBuscarJob` | high |
| `/status` | Alpha | type:5 deferred | `ProcessStatusJob` | default |
