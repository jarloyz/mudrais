# Documentador Template — MUDRAIS
> Cargado por @documentador al crear una HU. Contiene bounded contexts válidos, template y escala de puntos.

---

## Bounded Contexts Válidos en MUDRAIS

El campo `bounded_context` de toda HU DEBE ser exactamente uno de estos valores:

| Valor | Descripción | Archivos principales |
|---|---|---|
| `Registration` | Onboarding Discord, máquina de estados del registro | `app/Jobs/Discord/Registration/`, `app/Http/Controllers/Discord/` |
| `Economy` | MUDRAIS/monedas, ítems, player_transactions, energía | `app/Domains/Economy/`, `app/Application/UseCases/Economy/` |
| `Matchmaking` | Motor vectorial, GuildValidationService, partner search | `app/Domains/2_Matchmaking/`, `app/Application/Services/` |
| `Archetypes` | Arquetipos, guilds, guild_profiles, player_archetype_profiles | `app/Domain/Archetypes/`, `app/Models/Archetype*` |
| `Interview` | Dynamic Interviewer Agent, /interview command, multi-turno | `app/Jobs/Discord/Interview/`, `app/Application/UseCases/Interview/` |
| `Narrative` | Activity/Scene, turns, RAG pipeline, TurnProcessor | `app/Domain/Scene/`, `app/Application/UseCases/` |
| `Admin` | Filament admin panel, configuración LLM, AgentConfig | `app/Filament/`, `app/Models/AgentConfig*`, `app/Models/AiProvider*` |
| `Infrastructure` | Qdrant, embeddings, AI providers, VectorRetrievalService | `app/Infrastructure/Ai/`, `app/Infrastructure/Persistence/` |
| `Transversal` | Más de un bounded context afectado | — |

---

## Template de HU (copiar y rellenar)

```markdown
---
id: HU-NNN
title: [Título conciso en imperativo]
bounded_context: [Valor exacto de la tabla]
status: DRAFT
priority: [Alta | Media | Baja]
story_points: [1 | 2 | 3 | 5 | 8 | 13]
created_at: YYYY-MM-DD
---

# HU-NNN: [Título]

## Historia
**Como** [rol/actor],
**quiero** [acción o funcionalidad específica],
**para** [valor de negocio medible].

## Criterios de Aceptación (BDD)

### Escenario 1: [Flujo principal — happy path]
**Dado** [contexto y precondición]
**Cuando** [acción del usuario o evento del sistema]
**Entonces** [resultado observable y verificable]
**Y** [resultado adicional si aplica]

### Escenario 2: [Caso de error o estado alternativo]
**Dado** [...]
**Cuando** [...]
**Entonces** [...]

### Escenario 3: [Validación o caso límite]
...

## Componentes Técnicos Afectados

| Componente | Detalle |
|---|---|
| Bounded Context | [De la tabla arriba] |
| Capa(s) | Domain / Application / Infrastructure / Filament / Jobs |
| Comando/Evento Discord | `/comando` o "Ninguno" |
| Jobs involucrados | [NombreJob] o "Ninguno" |
| Tablas/Modelos | [nombre] o "Ninguno nuevo" |
| Colecciones Qdrant | [nombre] o "Ninguna" |
| Archivos i18n | `lang/es/discord.php`, `lang/en/discord.php` o "No aplica" |

## Dependencias
- HUs previas requeridas: [HU-NNN o "Ninguna"]
- Servicios externos: [Discord API / Qdrant / OpenRouter / "Ninguno"]

## Notas Técnicas
[Restricciones del dominio, invariantes no obvios, workarounds, claves i18n esperadas]
```

---

## Preguntas de Interrogación Dirigida (Paso 2)

Usar solo las pertinentes. Máximo 5 por ronda.

1. ¿Quién es el actor principal? (jugador Discord, operador Filament, sistema/Job, agente IA)
2. ¿Cuál es el valor de negocio medible?
3. ¿Cuál es el flujo principal paso a paso?
4. ¿Qué pasa si falla? (casos de error, estados alternativos)
5. ¿Qué componentes técnicos están claramente involucrados? (Job, UseCase, Service, modelo, slash command)
6. ¿Hay dependencias con otras HUs o sistemas externos?
7. ¿La HU genera texto visible al usuario en Discord? ¿En qué idiomas?

---

## Escala de Story Points para MUDRAIS

| Puntos | Referencia |
|---|---|
| 1 | Clave i18n, ajuste de embed, config Filament — < 1 hora |
| 2 | Job simple con respuesta Discord + test — ~2 horas |
| 3 | UseCase con repositorio, slash command, tests, i18n — ~4 horas |
| 5 | Feature completa multi-capa DDD + i18n + tests — ~1 día |
| 8 | Feature compleja: múltiples Jobs, Qdrant, nueva tabla — ~2 días |
| 13 | Feature grande o incierta — dividir en subtareas primero |

Si la estimación supera 8 → sugerir dividir la HU antes de aprobarla.

---

## Validación Interna — Los 3 Pilares

Verificar antes de mostrar el borrador al usuario:

| Pilar | NO-GO si... | Fix |
|---|---|---|
| **Ambigüedad** | Criterios con "rápido", "fácil", "adecuado" sin métricas | Reformular con verbo + resultado medible |
| **Spec-Driven** | < 2 escenarios BDD, o "Cuando" con múltiples acciones | Separar en escenarios distintos |
| **Técnico** | `bounded_context` no en tabla, o componentes Discord sin i18n | Exigir mapeo antes de aprobar |
