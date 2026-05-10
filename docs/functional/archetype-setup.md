# Guía Completa: Crear y Configurar un Archetype

Todo lo necesario para crear una comunidad nueva (archetype) desde cero en Filament,
incluyendo entity types, mutadores, prompts y filtros de matchmaking.

Documentos relacionados:
- [prompt-configuration.md](prompt-configuration.md) — referencia de placeholders y lógica de resolución
- [prompt-flow.md](prompt-flow.md) — cómo los prompts se usan en el pipeline de IA

---

## Conceptos previos

Antes de crear un archetype, entender la jerarquía:

```
Archetype  (ej: "Semantic Reading")
  ├── ArchetypePrompt × 3-4          (gatekeeper, context_injection, player_profile, vault)
  ├── ArchetypeEntityType × N        (ej: "Libro" [avatar], "Busco coautor" [activity])
  │     ├── system_prompt            (template del ContextOptimizerAgent)
  │     ├── matching_filters         (pre-filtro de matchmaking, solo activities)
  │     └── ArchetypeMutator × N     (campos del formulario)
  └── ArchetypeMutator × 4 base     (registration: red_lines, yellow_lines, preferences, style)
```

---

## Paso 1 — Crear el Archetype

`/admin/archetypes` → **New Archetype**

| Campo | Descripción | Ejemplo |
|-------|-------------|---------|
| Nombre | Nombre visible en UI | `Semantic Reading` |
| Descripción | Resumen público de la comunidad | `Club de lectura semántico. Matchmaking...` |
| ¿Activo? | Si está disponible para los guilds | ✅ |

Al guardar → el sistema dispara `IndexArchetypeJob` en background.

---

## Paso 2 — Configurar los Prompts de IA

Panel: Archetypes → [tu archetype] → tab **Prompts de IA**

Necesitas crear **al menos 2** prompts:

### 2a. `gatekeeper` — Extractor de perfil

Extrae campos estructurados del texto libre del usuario durante `/registro` o `/ficha`.

**Estructura base:**
```
You are a data extractor for [DOMAIN] community profiles.
Complete fields that are null or empty using the original profile text.

JSON FIELDS:
- [campo_1]: [tipo y valores válidos]
- [campo_2]: [tipo y valores válidos]
- red_lines: array of hard forbidden content (translate to English)
- yellow_lines: array of tolerated-but-disliked content (translate to English)
- affinities: array ordered by priority (translate to English)
- raw_profile: full preferences section (keep original language)

RULES:
1. Reply ONLY with the complete JSON. No additional text.
2. Translate all array values (except raw_profile) to English.
3. Do not modify fields that already have values.
```

**Placeholders requeridos:** `{partial_json_payload}` y `{raw_text_payload}`

**Campos específicos por dominio:**

| Dominio | Campos adicionales |
|---------|-------------------|
| TTRPG Texto | `experience_level` (Novice/Veteran/Master), `verbosity` (High/Medium/Low) |
| TTRPG Voz | + `availability_hours_per_week` (int), `microphone_quality` (Basic/Good/Professional) |
| Libros | `reading_pace` (Slow/Moderate/Fast), `preferred_formats` (Physical/Ebook/Audiobook) |
| Gaming | `skill_level` (Casual/Intermediate/Competitive), `platforms` (array: PC/PS5/Switch…) |

---

### 2b. `context_injection` — Reglas de dominio para el ContextOptimizer

Se inyecta vía `{archetype_prompt_injection}` en el `system_prompt` del entity_type.
**Solo debe contener reglas de dominio** — sin instrucciones de formato de output.

```
## [Domain] Guidelines

TERMINOLOGY — use precise [domain] terms:
- [Category 1]: "[term1]", "[term2]", "[term3]"
- [Category 2]: "[term4]", "[term5]"

DIMENSIONS to emphasize:
- [Dimension 1]: [description]
- [Dimension 2]: [description]

NO NEGATIVE LOGIC: Never include exclusions or negations.
Restrictions become positive structural focuses (e.g., "strictly non-X" instead of "no X").
```

> **Importante:** Este prompt NO debe contener instrucciones sobre el formato JSON.
> Tampoco debe contener `{archetype_prompt_injection}` u otros placeholders.
> La instrucción de output formato vive en el `system_prompt` del entity_type.

**Ejemplo real — Semantic Reading:**
```
## Literary Domain Guidelines

TERMINOLOGY — use precise literary and bibliographic terms:
- Genres: "gothic fiction", "hard science fiction", "magical realism", "epistolary novel"
- Narrative techniques: "unreliable narrator", "stream of consciousness", "frame narrative"
- Atmosphere: "Kafkaesque", "Lovecraftian", "Borgesian", "gothic atmosphere"
- Pacing: "slow-burn", "breakneck thriller", "meandering literary pace"

DIMENSIONS to emphasize:
- NARRATIVE STRUCTURE: POV, temporal structure, frame narratives, reliability of narrator
- PROSE STYLE: sentence density, lyrical quality, use of metaphor, dialog ratio
- THEMATIC DEPTH: philosophical undertones, social commentary, emotional register
- READER EXPERIENCE: immersive vs. intellectual, pacing, page-turn quality

NO NEGATIVE LOGIC: Translate any restrictions into positive structural focuses.
```

---

### 2c. `player_profile` — Inyección para perfil del jugador (legacy pipeline)

Se usa cuando el pipeline legacy (StyleOptimizer/OptimizerProfile) está activo.
Describe el dominio para el optimizer de perfil del jugador.

---

### 2d. `vault` — Inyección para optimización de Vault (opcional)

Solo necesario si el archetype usa Vaults (mundos narrativos).

---

## Paso 3 — Crear los Mutadores de formulario

Panel: Archetypes → [tu archetype] → tab **Mutadores**

Los mutadores definen qué campos aparecen en los formularios Discord y cómo se procesan.

### Mutadores base (todos los archetypes — contexto `registration`)

Creados automáticamente por `BaseProfileMutatorSeeder`. Si no existen, crearlos manualmente:

| Campo | Context | storage_mode | Propósito |
|-------|---------|--------------|-----------|
| `red_lines` | `registration` | `raw` | Límites absolutos del jugador |
| `yellow_lines` | `registration` | `raw` | Preferencias a evitar |
| `preferences` | `registration` | `semantic` | Géneros/temáticas favoritas |
| `style` | `registration` | `semantic` | Estilo narrativo/de juego |

### Mutadores por Entity Type

Cada entity type tiene su propio conjunto de mutadores según su `context`:

**Context `avatar_context`** (para entity_type = avatar):
| Campo | storage_mode | Ejemplo |
|-------|--------------|---------|
| Campos narrativos/descriptivos | `semantic` | appearance, lore, synopsis, personality |
| Campos de clasificación usados en búsquedas | `both` | char_class, author, themes_and_tropes |
| Datos demográficos filtros | `raw` | char_age, char_gender, year |

**Context `activities_vibe`** (para entity_type = activity):
| Campo | storage_mode | Ejemplo |
|-------|--------------|---------|
| Tipo/subtipo de actividad | `semantic` | project_type, scenario |
| Estilo de colaboración | `semantic` | collaboration_style, tone |
| Nivel de compromiso | `both` | commitment_level, session_frequency |
| Géneros/temáticas de la actividad | `semantic` | project_genre, themes |

### Regla del storage_mode

| `storage_mode` | Va al LLM | Guardado en DB | Cuándo usarlo |
|----------------|-----------|----------------|---------------|
| `raw` | ❌ | ✅ | Datos de filtro, datos demográficos, límites |
| `semantic` | ✅ | ✅ | Preferencias, estilos, descripciones |
| `both` | ✅ | ✅ | Clasificadores que son filtro Y semántica |

---

## Paso 4 — Crear los Entity Types

Panel: Archetypes → [tu archetype] → tab **Tipos de Entidad** → **New**

### Campos del formulario

| Campo | Descripción |
|-------|-------------|
| Entidad | `avatar` (contexto creado por el jugador) o `activity` (búsqueda LFG) |
| Clave interna | `type_key` — identificador único, snake_case (ej: `book_profile`, `busco_coautor`) |
| Label visible | `type_label` — nombre en UI/Discord |
| Orden | `sort_order` — posición en listas |
| Activo | Visible para los jugadores |
| Descripción | Descripción interna |
| Prompt del Optimizador LLM | `system_prompt` — ver sección 4a |
| Filtros de Matchmaking | `matching_filters` — ver sección 4b |

---

### 4a. `system_prompt` del Entity Type

Template base que usa el `ContextOptimizerAgent` para vectorizar la entidad.

**Estructura obligatoria:**
```
You are a Semantic Data Optimizer for a vector-based (RAG) [DOMAIN] system.
[Descripción específica del dominio y propósito del vector]

## [Título de sección — Datos de entrada]
{context_data_json}

## [Título de sección — Reglas del dominio]
{archetype_prompt_injection}

---

STRICT INSTRUCTIONS:

1. RAW JSON ONLY: Your final output must be exclusively a valid JSON object.
   Do not include markdown formatting (```json), explanations, or preambles
   outside of your thinking block.

2. NO NEGATIVE LOGIC: NEVER include exclusions (e.g., "no romance", "avoids horror").
   Translate all restrictions into positive structural focuses
   (e.g., "strictly non-romantic literary fiction").

EXPECTED JSON SCHEMA:
{
  "optimized_text_en": "[Descripción del formato esperado — 60-150 palabras en inglés]",
  "semantic_tag_query": "Dense string of 10-25 comma-separated taxonomic keywords"
}
```

**Placeholders y cuándo usarlos:**

| Placeholder | Cuándo usarlo | Fuente |
|-------------|--------------|--------|
| `{context_data_json}` | Entity = **avatar** — datos de la entidad (libro, personaje, juego) | Mutadores con storage=semantic/both del entity_type |
| `{user_soft_data_json}` | Entity = **activity** donde los datos son del jugador (no de una entidad) | Mutadores con storage=semantic/both del entity_type |
| Ambos | Entity = **activity** que referencia una entidad específica (gaming: juego + preferencias) | Ambos conjuntos de mutadores |
| `{archetype_prompt_injection}` | **Siempre** — obligatorio | `ArchetypePrompt (context_injection)` |
| `{vault_context}` | Entity = activity con Vault asociado | `Vault.name + Vault.description` |

> **Regla crítica:** El vector de un avatar debe describir **la entidad**, no al jugador que la creó.
> Si el entity_type es avatar, usar `{context_data_json}`. Solo las activities usan `{user_soft_data_json}`.

**Ejemplos reales por tipo de entidad:**

**Avatar — Libro (Semantic Reading):**
```
You are a Semantic Data Optimizer for a vector-based (RAG) literary reader matchmaking system.
Your task is to extract a semantic fingerprint from a book profile to match readers
via cosine similarity.

## Book Profile
{context_data_json}

## Literary Domain Guidelines
{archetype_prompt_injection}

---

STRICT INSTRUCTIONS:
1. RAW JSON ONLY: [...]
2. NO NEGATIVE LOGIC: [...]

EXPECTED JSON SCHEMA:
{
  "optimized_text_en": "Dense English paragraph (60-150 words) synthesizing genre,
    narrative style, thematic depth, pacing, and atmosphere. ONLY positive attributes.",
  "semantic_tag_query": "10-25 comma-separated literary terms: genre, technique, atmosphere, era"
}
```

**Activity — Busco coautor (Semantic Reading):**
```
You are a Semantic Data Optimizer for a vector-based (RAG) collaborative writing matchmaking system.
Your task is to extract a semantic fingerprint from a writer's project search to match
compatible co-authors via cosine similarity.

## Writer Project
{user_soft_data_json}

## Literary Domain Guidelines
{archetype_prompt_injection}

---

STRICT INSTRUCTIONS:
1. RAW JSON ONLY: [...]
2. NO NEGATIVE LOGIC: [...]

EXPECTED JSON SCHEMA:
{
  "optimized_text_en": "Dense English paragraph (60-150 words) describing the writing
    project type, collaboration style, genre and thematic focus, commitment level,
    and narrative voice preferences.",
  "semantic_tag_query": "10-25 comma-separated terms: project type, genre, style, commitment"
}
```

---

### 4b. `matching_filters` — Filtros de matchmaking (solo entity = activity)

Permite pre-filtrar candidatos por campos del perfil del jugador antes del semantic search.
Se configura en el formulario de creación/edición del Entity Type, bajo **Filtros de Matchmaking**.

Cada filtro tiene 3 campos:

| Campo | Descripción | Ejemplo |
|-------|-------------|---------|
| `profile_field` | `field_key` de un mutador `raw` en contexto `registration` del perfil | `is_writer` |
| `operator` | Operador de comparación | `eq` (igual a) |
| `value` | Valor esperado | `true` / `false` / `veteran` |

**Cuándo usar matching_filters:**
- Activity que requiere una habilidad específica (coautor, coach, streamer)
- Activity que requiere cierto nivel (torneo competitivo)
- Activity que requiere cierta plataforma (exclusivo PS5)

**Ejemplo: "Busco coautor" solo muestra escritores:**
```json
[
  { "profile_field": "is_writer", "operator": "eq", "value": "true" }
]
```

El campo `is_writer` debe existir como mutador `raw` en el contexto `registration`
del archetype. El `MatchingFilterService` hace una query JSONB en PostgreSQL
(`content_raw->>'is_writer' = 'true'`) antes de lanzar el semantic search en Qdrant.

---

## Paso 5 — Verificar que todo funciona

### 5a. Verificar indexación del archetype

```bash
./vendor/bin/sail artisan tinker
```

```php
$a = \App\Domains\Matchmaking\Models\Archetype::where('name', 'Mi Archetype')->first();
echo $a->is_hub_indexed ? '✅ Indexado' : '⏳ Pendiente';
echo "\nQdrant ID: " . $a->archetype_hub_qdrant_id;
```

### 5b. Verificar que los mutadores están correctos

```php
$arch = \App\Domains\Matchmaking\Models\Archetype::where('name', 'Mi Archetype')->first();
$mutators = \App\Domains\Matchmaking\Models\ArchetypeMutator::where('archetype_id', $arch->id)
    ->orderBy('context')->orderBy('sort_order')->get();
foreach ($mutators as $m) {
    $mode = is_object($m->storage_mode) ? $m->storage_mode->value : $m->storage_mode;
    echo sprintf('[%s] %s | %s', $m->context, $m->field_key, $mode) . PHP_EOL;
}
```

### 5c. Re-despachar un avatar específico para probar el pipeline

```php
\App\Jobs\IndexAvatarJob::dispatchSync('uuid-del-avatar');
```

### 5d. Verificar logs del pipeline

```bash
./vendor/bin/sail artisan log:tail --channel=qdrant
```

---

## Checklist completo

### En Filament → Archetypes → [nuevo] → **Prompts**
- [ ] `gatekeeper`: extrae JSON de campos + red/yellow_lines + affinities
- [ ] `context_injection`: solo reglas de dominio (terminología, dimensiones). Sin instrucciones de formato

### En Filament → Archetypes → [nuevo] → **Entity Types** (para cada entidad)
- [ ] Crear registro con `type_key` en snake_case, `type_label` en idioma del admin
- [ ] `system_prompt` contiene:
  - [ ] Rol del LLM específico del dominio
  - [ ] `{context_data_json}` (avatars) o `{user_soft_data_json}` (activities puras)
  - [ ] `{archetype_prompt_injection}` (siempre)
  - [ ] `{vault_context}` (si aplica para activities con Vault)
  - [ ] Regla `RAW JSON ONLY`
  - [ ] Regla `NO NEGATIVE LOGIC`
  - [ ] Sección `EXPECTED JSON SCHEMA` con `optimized_text_en` y `semantic_tag_query`
- [ ] `matching_filters` configurado si la activity requiere pre-filtro de perfil

### En Filament → Archetypes → [nuevo] → **Mutadores**
- [ ] Contexto `registration`: `red_lines` (raw), `yellow_lines` (raw), `preferences` (semantic), `style` (semantic)
- [ ] Contexto `avatar_context` (si hay entity type avatar): campos descriptivos como `semantic`, clasificadores como `both`
- [ ] Contexto `activities_vibe` (si hay entity type activity): campos de la actividad como `semantic`/`both`
- [ ] Si hay `matching_filters` en un entity_type de activity: el `profile_field` referenciado debe existir como mutador `raw` en contexto `registration`

---

## Errores comunes

| Error | Síntoma | Fix |
|-------|---------|-----|
| `{context_data_json}` ausente | LLM no recibe datos, alucina contenido | Añadir placeholder al `system_prompt` |
| `{archetype_prompt_injection}` ausente | Pipeline corre sin reglas de dominio | Añadir placeholder al `system_prompt` |
| `context_injection` prompt no existe | WARNING en logs: "Usando fallback optimizer" | Crear prompt con agent_type=context_injection |
| Entity type sin mutadores `semantic` | `{context_data_json}` vacío | Crear mutadores con storage_mode=semantic o both |
| `matching_filters` con campo inexistente | `MatchingFilterService` devuelve 0 resultados | Verificar que el `profile_field` existe como mutador raw en registration |
| LLM devuelve párrafo en vez de JSON | Falta instrucción `RAW JSON ONLY` | Añadir la instrucción al `system_prompt` |
| `system_prompt` usa formato "flowing paragraph" | RuntimeException en ContextOptimizerAgent | Cambiar a `EXPECTED JSON SCHEMA` con las dos claves |
