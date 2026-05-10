# MUDRAIS — Arquitectura del Sistema

Documento de referencia para agentes y desarrolladores. Leer antes de tocar cualquier código.

Documentos relacionados:
- [prompt-flow.md](prompt-flow.md) — Mapa completo de prompts: pipelines, agentes, placeholders
- [prompt-configuration.md](prompt-configuration.md) — Cómo configurar prompts en Filament
- [archetype-setup.md](archetype-setup.md) — Guía paso a paso para crear un archetype

---

## 1. Qué es MUDRAIS

Plataforma SaaS B2B multi-tenant construida sobre Discord. Provee un motor de matchmaking
semántico (RAG + vectores) para comunidades de roleplay, clubes de lectura, gaming y otros
contextos colaborativos. Cada servidor de Discord es un tenant independiente (Guild).

El flujo central: un jugador se registra → su perfil se vectoriza → el motor empareja
jugadores compatibles → los conecta en actividades colaborativas.

---

## 2. Stack

| Capa | Tecnología |
|------|-----------|
| Backend | PHP 8.x, Laravel 11+ |
| Base de datos relacional | PostgreSQL |
| Base de datos vectorial | Qdrant |
| Admin panel | Filament |
| Frontend reactivo | Alpine.js |
| LLM gateway | OpenRouter (modelos: configurable por guild/jugador) |
| Embeddings | nvidia/llama-nemotron-embed-vl-1b-v2 (2048 dims) |
| Moderación | OpenAI Moderation API |
| Transport | Discord (slash commands, modales, webhooks) |
| Entorno | Laravel Sail (Docker) |

---

## 3. Principios arquitectónicos

- **Domain-Driven Design (DDD)**: 4 Bounded Contexts con ServiceProviders propios
- **SOLID**: cada clase tiene una responsabilidad, dependencias invertidas via Contracts
- **Repository / Service pattern**: infraestructura detrás de interfaces, nunca acceso directo
- **Dependency direction**: Presentation → Application → Domain ← Infrastructure
  (la infra implementa contratos del dominio, nunca al revés)
- **Sin framework bleeding**: la lógica de dominio no importa clases de Laravel directamente
- **Async por defecto**: operaciones costosas (LLM, embedding, Qdrant) via Jobs en cola `heavy`
- **Multi-LLM**: la capa Intelligence abstrae OpenRouter, Anthropic, Ollama — intercambiables

---

## 4. Bounded Contexts (Dominios)

### `1_Community` — SaaS & Identidad

Gestiona todo lo relacionado con tenants y usuarios.

| Modelo | Qué representa |
|--------|---------------|
| `Guild` | Un servidor de Discord = un tenant |
| `GuildProfile` | Configuración del tenant (archetype activo, suscripción, etc.) |
| `Player` | Un usuario de Discord registrado en el sistema |
| `GuildMember` | Relación Player ↔ Guild (un player puede estar en múltiples guilds) |
| `GameItem` | Ítem de economía virtual, configurable por guild |
| `PlayerTransaction` | Registro de movimientos de moneda del jugador |

**Regla:** Un `Player` existe a nivel global (su Discord ID), pero su membresía y permisos son por Guild.

---

### `2_Matchmaking` — Motor de Compatibilidad

Gestiona archetypes, configuración de mutadores y la lógica de indexación vectorial.

| Modelo | Qué representa |
|--------|---------------|
| `Archetype` | Categoría de comunidad (TTRPG Texto, Semantic Reading, Gaming…) |
| `ArchetypePrompt` | Prompt de IA por archetype y tipo de agente |
| `ArchetypeEntityType` | Define un tipo de entidad con su `system_prompt` para el ContextOptimizer |
| `ArchetypeMutator` | Define cada campo del formulario con su `storage_mode` y `context` |
| `PlayerArchetypeProfile` | Vector semántico del jugador dentro de un archetype específico |
| `ArchetypeDraft` | Archetype pendiente de aprobación |

**Enum `MutatorStorageMode`:**
- `raw` → guardado literal en DB. NO va al LLM optimizer (age, gender, red_lines)
- `semantic` → pasa al optimizer via `{context_data_json}` (appearance, lore, synopsis, etc.)
- `both` → guardado raw Y enviado al optimizer (char_class, author, themes_and_tropes)

**Contextos de mutador (`context`):**
- `registration` → perfil base del jugador (preferences, style, red_lines, etc.)
- `avatar_context` → datos del Context Entity (personaje, libro, juego, etc.)
- `activities_vibe` → datos de la actividad/búsqueda de pareja

**Agent types de `ArchetypePrompt`:**
- `gatekeeper` → extrae campos del texto libre del usuario
- `context_injection` → reglas de dominio inyectadas en el ContextOptimizer (usa `{archetype_prompt_injection}`)
- `optimizer` → fallback legacy para StyleOptimizer/OptimizerProfile; standalone completo
- `player_profile` → inyección en el pipeline de perfil del jugador
- `vault` → inyección en el pipeline de optimización de Vaults

---

### `3_Narrative` — Motor Narrativo

Gestiona el contenido generado, las sesiones activas y el estado de la historia.

| Modelo | Qué representa |
|--------|---------------|
| `Avatar` | **Ver Glosario** — Context Entity (capa de conocimiento semántico) |
| `Vault` | Mundo/setting narrativo. Contiene lore, reglas, ambientación |
| `Activity` | Sesión activa de colaboración |
| `Scene` | Segmento de una Activity con su propio contexto narrativo |
| `Character` | Hoja de personaje estructurada |
| `Continuity` | Rama de la línea temporal |
| `LoreEntry` | Entrada de conocimiento del mundo (vectorizada en Qdrant) |

**Relaciones importantes:**
- Un `Avatar` pertenece a un `ArchetypeEntityType` → define qué campos y qué prompt usa
- Un `Avatar` puede estar asociado a un `Vault` → el `{vault_context}` enriquece su vector
- `Avatar.name` = campo del sistema (título del libro, nombre del personaje, nombre del juego)
  Siempre se inyecta automáticamente como primer campo en `{context_data_json}` bajo la clave `"Name"`

---

### `4_Intelligence` — Abstracción de IA

Solo contiene contratos (interfaces). Las implementaciones están en `Infrastructure/Ai/`.

| Contrato | Propósito |
|----------|-----------|
| `AiChatGateway` | Chat con cualquier LLM |
| `EmbeddingGateway` | Obtener vector float[] de un texto |
| `AgentGateway` | Orquestar agentes de escritura de turnos |

---

## 5. Glosario — Términos con significado no obvio

### `Avatar` ≠ avatar de personaje

> **`Avatar` es un Context Entity — una capa de conocimiento semántico.**

El nombre es legacy. Su significado real: cualquier entidad que aporte contexto a una actividad.
Un `Avatar` puede ser:
- Un **personaje** de roleplay (TTRPG)
- Un **libro** o título literario (Semantic Reading)
- Un **juego** o título gaming (Gaming)
- Una **ubicación** o escenario
- Una **búsqueda** o actividad LFG (entity = activity)

**Lo que NO es un Avatar:** el perfil del jugador (`PlayerArchetypeProfile`).
El vector del Avatar describe **la entidad en sí**, no a quien la creó.

---

### `Guild` = tenant de Discord

`Guild` = el servidor Discord que contrató el servicio. Puede ser un servidor de TTRPG,
un club de lectura, una comunidad gamer, etc.

---

### `Archetype` = modo/categoría de la comunidad

`Archetype` define el flujo de registro, los campos del formulario y los prompts de IA.
Un guild puede tener múltiples archetypes activos simultáneamente.

---

### `ArchetypeEntityType`

Define un **tipo de entidad** dentro de un archetype. Ejemplos:
- Archetype "TTRPG Texto" → EntityTypes: "Personaje" (avatar), "Búsqueda 1x1" (activity)
- Archetype "Semantic Reading" → EntityTypes: "Libro" (avatar), "Busco coautor" (activity)

Cada EntityType tiene su propio `system_prompt` para el `ContextOptimizerAgent`,
sus propios `ArchetypeMutator` con los campos semánticos, y opcionalmente
`matching_filters` para pre-filtrado de matchmaking.

---

### `matching_filters` en `ArchetypeEntityType`

Array JSON de reglas declarativas para pre-filtrar candidatos antes del semantic search.
Solo aplicable a entity_types de tipo `activity`.

```json
[
  { "profile_field": "is_writer", "operator": "eq", "value": "true" }
]
```

El `MatchingFilterService` evalúa estas reglas contra `PlayerArchetypeProfile.content_raw`
(campo JSONB) y devuelve solo los IDs de perfiles que las cumplen. Esos IDs se pasan a
Qdrant como filtro duro antes del semantic search.

---

## 6. Pipeline de indexación vectorial (flujo crítico)

```
Usuario completa formulario Discord
          │
          ▼
[GatekeeperAgent]
  Prompt: ArchetypePrompt (agent_type='gatekeeper')
  Input:  texto libre del usuario
  Output: JSON estructurado con campos del perfil → Avatar.content_raw
          │
          ▼  IndexAvatarJob (cola 'heavy')
          │
[EntityTypePromptBuilderService]
  Lee:     ArchetypeEntityType.system_prompt
  Filtra:  ArchetypeMutator donde storage_mode IN (semantic, both)
           y context = entityType.getMutatorContext()
  Inyecta: {context_data_json}           ← campos semánticos, precedidos por Avatar.name
  Inyecta: {archetype_prompt_injection}  ← ArchetypePrompt (agent_type='context_injection')
                                           Fallback: agent_type='optimizer' (con WARNING)
  Inyecta: {vault_context}               ← si el Avatar tiene Vault asociado (solo activity)
          │
          ▼ prompt final (5000-6000 chars típico)
          │
[ContextOptimizerAgent]
  System message: fuerza RAW JSON ONLY (defensa sistémica en código)
  User message:   prompt construido arriba
  Output: { optimized_text_en: string, semantic_tag_query: string }
          │
          ├─→ Avatar.optimized_text_en guardado
          ├─→ Avatar.semantic_tag_query guardado
          └─→ NormalizeAvatarTagsJob despachado
          │
          ▼
[EmbeddingGateway]
  Model: nvidia/llama-nemotron-embed-vl-1b-v2
  Input: optimized_text_en
  Output: float[] (2048 dims)
          │
          ▼
[QdrantService.upsertHubPoint]
  Colección: matchmaking_hub  |  Vector name: avatar_context
  Payload: { avatar_id, owner_profile_id, guild_ids[], archetype_id, is_lfg, tags[] }
```

**Pipeline legacy** (fallback si `ArchetypeEntityType.system_prompt` está vacío):
```
[StyleOptimizerAgent]
  Paso 1: gatekeeper → JSON { positives[], red_lines[], yellow_lines[] }
  Paso 2: optimizer  → párrafo semántico denso
  → embedding → Qdrant
```

---

## 7. Pipeline de perfil del jugador

```
/registro Discord (modal)
          │
[PlayerRegistrationService]
  Crea o actualiza Player + GuildMember
          │
[GatekeeperProfileService]
  Usa: ArchetypePrompt (agent_type='gatekeeper')
  Extrae campos del texto libre → PlayerArchetypeProfile.content_raw
          │
[NormalizePlayerTagsJob]
  semantic_tag_query → TagNormalizerService → canonical tags
          │
[IndexPlayerStyleJob]
  → embedding → Qdrant (colección: players_profiles)
```

---

## 8. Qdrant — colecciones y vectores

| Colección | Vector name | Qué indexa | Dimensiones |
|-----------|-------------|-----------|-------------|
| `matchmaking_hub` | `avatar_context` | Avatars (Context Entities) | 2048 |
| `players_profiles` | (por archetype) | Perfiles semánticos de jugadores | 2048 |
| `taxonomy_tags` | `tag_embedding` | Tags canónicos del sistema | 2048 |
| `historia_lore` | `lore_context` | Entradas de lore de Vaults | 2048 |

---

## 9. Agentes de IA — mapa rápido

| Agente | Responsabilidad | Prompt source |
|--------|----------------|---------------|
| `GatekeeperAgent` | Extrae campos estructurados del texto libre del usuario | `ArchetypePrompt (gatekeeper)` |
| `ContextOptimizerAgent` | Convierte datos semánticos en JSON optimizado para embedding | `ArchetypeEntityType.system_prompt` |
| `StyleOptimizerAgent` | Pipeline legacy de 2 pasos (gatekeeper → optimizer) | `ArchetypePrompt (optimizer)` |
| `VaultOptimizerAgent` | Optimiza el lore de un Vault para embedding | `AiPromptTemplate (vault_base)` |
| `ArchetypeOptimizerAgent` | Optimiza la descripción de un Archetype para embedding | `AiPromptTemplate (archetype_base)` |
| `TagNormalizerService` | Normaliza términos semánticos a tags canónicos | interno |
| `ContentSafetyAgent` | Moderación de contenido generado | `ContentSafetyPrompt` |
| `SceneWriterAgent` | Genera turnos narrativos *(pausado)* | `ArchetypePrompt (writer)` |

---

## 10. Reglas operativas para desarrollo

1. **Trabajar exclusivamente en `laravel_app/`** a menos que se indique lo contrario
2. **Nunca leer ni modificar `.env`** ni ninguna de sus variantes
3. **Comandos siempre via Sail**: `./vendor/bin/sail artisan [...]`, `./vendor/bin/sail test`
4. **Backend antes que frontend**: implementar DB/servicios antes que UI/Filament
5. **Tests obligatorios** para toda funcionalidad nueva (PSR-12, Unit Tests)
6. **Logging obligatorio** en toda función: ver estándar en `CLAUDE.md`
7. **Al terminar una tarea**: actualizar `estado` y `archivos_relacionados` en `estado_proyecto.json`
8. **Chat/TurnProcessor pausado**: no trabajar en `Scene`, `Continuity`, `TurnProcessorService`
   hasta que Archetypes + Guilds + Matchmaking estén completos

---

## 11. Estado actual del proyecto

| Área | Estado | Notas |
|------|--------|-------|
| Registro Discord + Gatekeeper | ✅ Funcionando | Pipeline completo |
| IndexAvatarJob + ContextOptimizer | ✅ Funcionando | System message forzado en código (JSON seguro) |
| Archetypes: Text-Based Roleplay | ✅ Completo | entity_types, mutadores, prompts configurados |
| Archetypes: Semantic Reading | ✅ Completo | Incluye "Busco coautor" con matching_filter is_writer |
| Archetypes: TTRPG Voz | 🔲 Pendiente | mutadores base creados, entity_type sin configurar |
| Archetypes: Gaming | 🔲 Pendiente | mutadores base creados, entity_type sin configurar |
| ArchetypePrompt context_injection | ✅ Implementado | Nuevo agent_type con prioridad sobre optimizer |
| matching_filters en entity_types | ✅ Implementado | MatchingFilterService + UI Filament + Qdrant filter |
| Avatar.name en {context_data_json} | ✅ Implementado | IndexAvatarJob inyecta name como primer campo |
| Guild multi-tenant | ✅ Funcionando | GuildProfile, suscripciones, comandos por guild |
| Matchmaking hub (Qdrant) | ✅ Funcionando | upsert y búsqueda en matchmaking_hub |
| Economía / ítems | ✅ Funcionando | GameItem, PlayerTransaction, GuildItemOverride |
| Chat / TurnProcessor | ⏸ Pausado | Reanudar tras completar Matchmaking |
| Filament admin | ✅ Base completa | Archetypes, EntityTypes, Mutators, Guilds |
