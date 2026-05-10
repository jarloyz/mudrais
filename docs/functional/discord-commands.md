# Comandos Discord — MUDRAIS

Referencia completa de todos los slash commands disponibles en el bot de MUDRAIS, incluyendo opciones, flujos de interacción y comportamiento esperado.

---

## Índice

1. [/registro](#1-registro)
2. [/ficha](#2-ficha)
3. [/create_vault](#3-create_vault)
4. [/create](#4-create)
5. [/actividad crear](#5-actividad-crear)
6. [/buscar-actividad](#6-buscar-actividad)
7. [/buscar-partner](#7-buscar-partner)
8. [/status](#8-status)
9. [Registro de Comandos en Discord](#9-registro-de-comandos-en-discord)

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

## 3. `/create_vault`

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

## 4. `/create`

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

## 5. `/actividad crear`

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

## 6. `/buscar-actividad`

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

## 7. `/buscar-partner`

**Descripción:** Busca jugadores compatibles (no actividades) usando el vector de perfil del jugador. Matchmaking de personas, no de actividades.

**Opciones:** ninguna

**Flujo:**
1. Deferred (type:5)
2. `ProcessBuscarJob` busca en `mudrais_profiles` o `players_profiles` según si hay perfil B2B de arquetipo
3. Devuelve Top 5 jugadores con score y tags en común

---

## 8. `/status`

**Descripción:** Muestra el estado actual del jugador: monedas, energía, perfil y estado del tutorial.

**Opciones:** ninguna

**Flujo:**
1. Deferred (type:5)
2. `ProcessStatusJob` consulta el player y devuelve embed con datos actuales

---

## 9. Registro de Comandos en Discord

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

| Comando | Respuesta inicial | Job despachado | Queue |
|---|---|---|---|
| `/registro` | type:4 embed | ninguno | — |
| `/ficha` | type:9 modal | `ProcessFichaModalJob` | default |
| `/create_vault` | type:9 modal | `ProcessVaultOnboardingJob` | default |
| `/create` | type:4 embed + botón | `ProcessCreateContextJob` | default |
| `/actividad crear` | type:9 modal | `ProcessCreateActividadJob` | default |
| `/buscar-actividad` | type:5 deferred | `ProcessBuscarActividadJob` | high |
| `/buscar-partner` | type:5 deferred | `ProcessBuscarJob` | high |
| `/status` | type:5 deferred | `ProcessStatusJob` | default |
