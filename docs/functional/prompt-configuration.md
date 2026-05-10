# Arquitectura de Prompts — Sistema de Optimización Semántica

Referencia operativa para configurar, mantener y depurar los prompts de IA del pipeline de indexación vectorial.

---

## 1. Flujo completo

```
Usuario Discord
     │  (texto libre del perfil / datos del formulario)
     ▼
[GatekeeperAgent]  ──── ArchetypePrompt (agent_type = 'gatekeeper')
     │  Extrae campos estructurados del texto libre → content_raw (JSON en DB)
     ▼
content_raw (DB)
     │  Filtrado por ArchetypeMutator (storage_mode = semantic | both)
     ▼
[EntityTypePromptBuilderService]
     │  Lee:     ArchetypeEntityType.system_prompt  ← editable en Filament
     │  Inyecta: {context_data_json}               ← mutadores con semantic/both
     │  Inyecta: {archetype_prompt_injection}       ← ArchetypePrompt (agent_type = player_profile u optimizer)
     │  Inyecta: {vault_context}                    ← si el avatar tiene Vault asociado
     ▼
[ContextOptimizerAgent]  ──── modelo LLM via OpenRouter
     │  system message forzado: "RAW JSON ONLY, required keys: optimized_text_en + semantic_tag_query"
     │  Retorna: { optimized_text_en: string, semantic_tag_query: string }
     ▼
[EmbeddingGateway]  ──── vector float[] (nvidia/llama-nemotron-embed-vl-1b-v2)
     ▼
[QdrantService]  ──── upsert en matchmaking_hub (colección avatar_context)
     │  También despacha: NormalizeAvatarTagsJob (semantic_tag_query → tags canónicos)
     ▼
Avatar indexado y visible en matchmaking
```

**Pipeline legacy** (se activa si `ArchetypeEntityType.system_prompt` está vacío):
```
[StyleOptimizerAgent]
  Paso 1 (gatekeeper): texto libre → JSON { positives[], red_lines[], yellow_lines[] }
  Paso 2 (optimizer):  positives[] → párrafo semántico denso
  → embedding → Qdrant
```

---

## 2. Inventario de prompts — dónde vive cada uno

| Modelo | Campo | `agent_type` / `key` | Editable desde | Propósito |
|--------|-------|----------------------|----------------|-----------|
| `ArchetypePrompt` | `system_prompt` | `gatekeeper` | Filament → Archetype → Prompts | Extrae campos estructurados del perfil en texto libre |
| `ArchetypePrompt` | `system_prompt` | `optimizer` *(legacy)* | Filament → Archetype → Prompts | Convierte affinidades en párrafo para StyleOptimizerAgent |
| `ArchetypePrompt` | `system_prompt` | `player_profile` | Filament → Archetype → Prompts | Reglas de dominio inyectadas via `{archetype_prompt_injection}` en perfiles |
| `ArchetypePrompt` | `system_prompt` | `vault` | Filament → Archetype → Prompts | Reglas de dominio inyectadas via `{archetype_prompt_injection}` en vaults |
| `ArchetypeEntityType` | `system_prompt` | — | Filament → Archetype → Entity Types | Template base del ContextOptimizerAgent. **Define el formato de salida** |
| `AiPromptTemplate` | `body` | `player_profile_base` | Seeder / DB directo | Template base genérico para perfiles (referencia, no se usa directo en pipeline) |
| `AiPromptTemplate` | `body` | `vault_base` | Seeder / DB directo | Template base para optimización de Vaults |
| `AiPromptTemplate` | `body` | `archetype_base` | Seeder / DB directo | Template base para optimización de Archetypes |

---

## 3. Qué es FIJO en cada entity_type system_prompt

Estas instrucciones deben estar presentes en **todos** los `ArchetypeEntityType.system_prompt`
sin excepción, independientemente del archetype o entidad:

```
1. Identidad del LLM:
   "You are a Semantic Data Optimizer for a vector-based (RAG) [...] system."

2. Regla de formato (OBLIGATORIO — si falta, el job falla silenciosamente):
   "RAW JSON ONLY: Your final output must be exclusively a valid JSON object.
   Do not include markdown formatting (```json), explanations, or preambles."

3. Regla de semántica positiva (OBLIGATORIO para calidad vectorial):
   "NO NEGATIVE LOGIC: NEVER include exclusions (e.g., 'no X', 'avoids Y').
   Translate all restrictions into positive structural focuses."

4. Sección EXPECTED JSON SCHEMA con las claves obligatorias:
   {
     "optimized_text_en": "...",
     "semantic_tag_query": "..."
   }

5. Placeholders requeridos en el body:
   {context_data_json}          ← OBLIGATORIO: datos reales del Context Entity (personaje, libro, etc.)
   {archetype_prompt_injection} ← OBLIGATORIO: reglas de dominio del archetype
   {vault_context}              ← OPCIONAL: solo para entity_type = activity con Vault

> NO usar {user_soft_data_json} en entity_type system_prompts — ese placeholder es para el pipeline
> de perfil del jugador (OptimizerProfileAgent). Un Context Entity es inmutable y compartible;
> mezclar datos del creador contamina el vector semántico de la entidad.
```

> **Nota:** `ContextOptimizerAgent` inyecta un `system message` adicional que fuerza el JSON,
> pero si falta `{context_data_json}` el LLM no recibe datos reales y puede alucinar.

---

## 4. Qué cambia por ARCHETYPE — `ArchetypePrompt`

### `gatekeeper` — campos a extraer por dominio

| Archetype | Campos específicos |
|-----------|-------------------|
| TTRPG Texto | `experience_level` (Novice/Veteran/Master), `verbosity`, `schedule` {description, timezone} |
| TTRPG Voz | + `availability_hours_per_week`, `preferred_session_length_hours`, `microphone_quality` (Basic/Good/Professional) |
| Libros | `reading_pace` (Slow/Moderate/Fast), `preferred_formats` (Physical/Ebook/Audiobook) |
| Gaming | `skill_level` (Casual/Intermediate/Competitive), `platforms` (PC/PS5/Switch…) |
| **Todos** | `red_lines[]`, `yellow_lines[]`, `affinities[]`, `raw_profile` |

Todos los valores deben salir en inglés. Los campos nulos no se tocan.

### `player_profile` (inyectado via `{archetype_prompt_injection}`) — terminología de dominio

| Archetype | Dimensiones a enfatizar | Terminología clave |
|-----------|------------------------|--------------------|
| TTRPG Texto | POV, Pacing, Genre, Character Development, Narrative Style | "slow-burn", "enemies-to-lovers", "grimdark", "high fantasy" |
| TTRPG Voz | Game systems, session format, microphone dynamics | "D&D 5e", "Pathfinder", "Call of Cthulhu", "couch co-op" |
| Libros | Narrative techniques, pacing, author style | "unreliable narrator", "epistolary format", "gothic atmosphere" |
| Gaming | Game genres, cooperation dynamics, competitive level | "soulslike", "tactical RPG", "open-world exploration" |

---

## 5. Qué cambia por ENTITY TYPE — `ArchetypeEntityType.system_prompt`

Dentro del mismo archetype, cada tipo de entidad tiene su propio `system_prompt`
porque el formato semántico de salida difiere:

### `entity = avatar` (personaje)

- **Formato `optimized_text_en`**: etiquetas pipe-separated
  ```
  ROLE: [arquetipo] | APPEARANCE: [físico] | PSYCHE: [personalidad/motivación] | LORE: [trasfondo] | AFFINITIES: [afinidades positivas]
  ```
- **Datos de entrada** (`{context_data_json}`): mutadores del contexto `avatar_context`
  - appearance (semantic), personality (semantic), lore (semantic), char_class (both)
  - char_age (raw → NO va al optimizer), char_gender (raw → NO va al optimizer)

### `entity = activity` (búsqueda de partida / rol 1x1)

- **Formato `optimized_text_en`**: párrafo narrativo libre (60-150 palabras)
  - Captura: atmósfera narrativa, pacing, longitud de post, dinámicas de poder, temáticas
  - Soporta `{vault_context}` para integrar el lore del mundo
- **Datos de entrada**: mutadores del contexto `activities_vibe`
  - scenario, relationship_type, post_length, tone, etc. (todos semantic)

### `entity = reader_profile` (Libros — perfil de lector)

- **Formato `optimized_text_en`**: párrafo semántico literario (60-150 palabras)
  - Captura: géneros, técnicas narrativas, pacing de lectura, estilo de autor
- **Datos de entrada**: mutadores del contexto `registration`
  - preferences (semantic), style (semantic)

---

## 6. Campo `Avatar.name` — campo del sistema, no mutador

`Avatar.name` es el identificador canónico del Context Entity (título del libro, nombre del personaje,
nombre del juego, etc.). No es un mutador porque es universal a todos los archetypes y se usa en UI,
Discord, logs y relaciones del modelo.

`IndexAvatarJob` lo inyecta automáticamente como primer campo de `{context_data_json}` bajo la clave `"Name"`,
antes de los campos de mutadores. Los entity_type system_prompts pueden referenciarlo como `Name` en su schema de salida.

**Regla:** No crear un mutador `title` o `nombre` para el nombre del avatar — usar `Avatar.name`.

---

## 7. `ArchetypeMutator` — qué datos llegan al LLM

Los mutadores controlan qué campos del `content_raw` entran en `{context_data_json}`:

| `storage_mode` | Comportamiento |
|----------------|----------------|
| `raw` | Guardado literal en DB. **NO va al LLM optimizer.** Usado para filtros hard (age, gender, red_lines) |
| `semantic` | Pasa al LLM optimizer via `{context_data_json}`. Ej: appearance, personality, lore, book synopsis |
| `both` | Guardado raw Y enviado al optimizer. Ej: char_class (filtro Y vector) |

### Contextos de mutadores

| `context` | Cuándo se usa |
|-----------|---------------|
| `registration` | Perfil base del jugador (preferences, style, red_lines, yellow_lines) |
| `avatar_context` | Datos del personaje (appearance, personality, lore, char_age, etc.) |
| `activities_vibe` | Datos de la actividad/búsqueda de partida |

**Solo se inyectan al prompt** los mutadores cuyo `storage_mode` sea `semantic` o `both`
y cuyo `context` coincida con `entityType.getMutatorContext()`.

---

## 8. Campos base de mutadores (todos los archetypes)

Creados por `BaseProfileMutatorSeeder` en contexto `registration`:

| `field_key` | `storage_mode` | Va al LLM | Propósito |
|-------------|----------------|-----------|-----------|
| `red_lines` | `raw` | NO | Contenido absolutamente prohibido |
| `yellow_lines` | `raw` | NO | Contenido que incomoda pero tolera |
| `preferences` | `semantic` | SÍ | Géneros/temáticas favoritas |
| `style` | `semantic` | SÍ | Estilo narrativo/de juego preferido |

Los labels varían por archetype:
- TTRPG: "Absolute Limits" / "Soft Limits" / "Favorite Genres" / "Your Narrative Style"
- Gaming: "Hard No's" / "Discomfort Zones" / "Favorite Mechanics" / "Your Gaming Vibe"
- Libros: "Reading Limits" / "Tropes to Avoid" / "Favorite Books/Authors" / "Your Reader Profile"

---

## 9. Checklist — configurar un nuevo archetype desde cero

### En Filament → Archetypes → [nuevo] → **Prompts**

- [ ] `gatekeeper`: prompt que extrae campos estructurados del texto libre del usuario
  - Output: JSON con `positives[]`, `red_lines[]`, `yellow_lines[]` + campos específicos del dominio
  - Regla: `Reply ONLY with the complete JSON. No additional text.`
  - Todos los valores en inglés
- [ ] `player_profile`: reglas de dominio que se inyectarán via `{archetype_prompt_injection}`
  - Define qué terminología usar, qué dimensiones enfatizar, qué NO incluir

### En Filament → Archetypes → [nuevo] → **Entity Types**

Para cada entidad (avatar, activity, etc.), crear un registro con `system_prompt` que incluya:

```
[Identidad del LLM — específica del dominio]

## [Título de sección de datos]
{context_data_json}

## [Título de reglas de dominio]
{archetype_prompt_injection}

---

STRICT INSTRUCTIONS:

1. RAW JSON ONLY: [...]
2. NO NEGATIVE LOGIC: [...]

EXPECTED JSON SCHEMA:
{
  "optimized_text_en": "[descripción del formato esperado]",
  "semantic_tag_query": "Dense string 10-25 words, comma-separated phrases"
}
```

**Errores comunes que rompen el pipeline silenciosamente:**
- Olvidar `{context_data_json}` (o escribir `{user_soft_data_json}`) → el LLM alucina sin datos reales
- Pedir "flowing paragraph" en vez de JSON → RuntimeException en ContextOptimizerAgent
- Usar `{vault_context}` sin que la entidad tenga Vault → placeholder queda vacío (inofensivo)

### En Filament → Archetypes → [nuevo] → **Mutators**

- [ ] Contexto `registration`: red_lines (`raw`), yellow_lines (`raw`), preferences (`semantic`), style (`semantic`)
- [ ] Contexto `avatar_context` (si aplica): appearance, personality, lore como `semantic`; char_age, char_gender como `raw`
- [ ] Los campos `raw` **nunca** llegan al optimizer pero sí se guardan para filtros de matchmaking

---

## 10. Diagnóstico rápido de fallos

| Síntoma en logs | Causa probable | Fix |
|----------------|----------------|-----|
| `JSON inválido del LLM` + respuesta narrativa | `system_prompt` pide "flowing paragraph" o falta `{context_data_json}` | Corregir `ArchetypeEntityType.system_prompt` en Filament |
| `JSON inválido del LLM` + respuesta vacía | `{context_data_json}` presente pero no hay mutadores `semantic` configurados | Crear mutadores en Filament con `storage_mode=semantic` |
| `No builtPrompt or softFields` | `system_prompt` vacío O `content_raw` del avatar está vacío | Verificar que el avatar tiene datos y que el entity_type tiene `system_prompt` |
| `ContextOptimizer falló` + job retorna sin indexar | Cualquiera de los anteriores — ver `IndexAvatarJob` línea 96 | Revisar logs previos del mismo avatar_id |
| `Usando StyleOptimizer pipeline (legacy)` | El avatar no tiene `entityType` o su `system_prompt` está vacío | Crear entity_type en Filament y configurar su `system_prompt` |

---

## 11. Modelos involucrados y sus relaciones

```
Archetype
  ├── hasMany ArchetypePrompt       (agent_type: gatekeeper, optimizer, player_profile, vault)
  ├── hasMany ArchetypeEntityType   (entity: avatar|activity, system_prompt)
  │     └── hasMany ArchetypeMutator (context: registration|avatar_context|activities_vibe)
  └── (pivot) AiPromptTemplate      (key: player_profile_base, vault_base, archetype_base)

Avatar
  ├── belongsTo ArchetypeEntityType
  ├── belongsTo Vault               (opcional — aporta {vault_context})
  └── content_raw: JSON             (campos según mutadores del entity_type)
```
