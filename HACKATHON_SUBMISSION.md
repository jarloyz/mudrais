# MUDRAIS — Hackathon Submission (lablab.ai)

## Submission Title

```
MUDRAIS: Semantic Matchmaking Engine
```

---

## Short Description

```
Multi-tenant SaaS that converts Discord user profiles into 2048-dim semantic vectors via AMD-accelerated Qwen3-VL-32B and Qdrant, enabling privacy-isolated partner matching across roleplay, book clubs, and gaming communities — no custom infrastructure required.
```

---

## Long Description

```
MUDRAIS (Multi-User Dynamic Roleplay AI System) solves a fundamental problem in online
communities: finding truly compatible collaborators. Traditional bots rely on keyword filters
that miss nuance — narrative tone, writing style, thematic compatibility. MUDRAIS replaces
that with a semantic matchmaking engine.

When a user registers, their free-text input enters a multi-agent AI pipeline running on
AMD MI300X via AMD Developer Cloud. A Gatekeeper agent (Llama 3.1-8B) extracts structured
fields from natural language. A Context Optimizer agent (Qwen3-VL-32B) generates a
semantically dense representation tuned for embedding. An embedding model then converts it
into a 2048-dimensional float vector stored in Qdrant.

The matchmaking engine runs cosine similarity search inside Qdrant, applying hard payload
filters for "red lines" (absolute blockers) and strict guild-level tenant isolation — users
from Server A mathematically cannot appear in Server B results. A secondary scoring layer
applies soft penalties (yellow lines, timezone mismatch) on top of raw cosine distance to
produce a final compatibility score.

The system is Discord-agnostic by design. Discord is just the transport layer (slash
commands, webhooks). The entire semantic pipeline — profile ingestion, multi-agent
optimization, vector indexing, and partner search — lives in a clean Domain-Driven Design
architecture on Laravel. The same engine can power Slack bots, web forms, or REST APIs
without touching domain code. Each community is an independent tenant with isolated Qdrant
payloads, its own archetype configuration, and its own LLM prompts — true B2B multi-tenancy
at scale.
```

---

## Main Track

**AI Agents and Agentic Workflows**

El núcleo del sistema es un pipeline multi-agente: Gatekeeper → ContextOptimizer → Embedding → Qdrant, cada agente con prompt y responsabilidad aislada.

---

## Technologies

| Tag | Razón |
|-----|-------|
| `amd` | Infraestructura de cómputo base |
| `AMD Developer Cloud` | Donde corre Qwen3-VL-32B en hardware MI300X |
| `Qwen` | Modelo optimizer: `qwen/qwen3-vl-32b-instruct` |
| `Qdrant` | Base de datos vectorial (colecciones, Named Vectors, payload filtering) |
| `OpenRouter` | Gateway multi-LLM (Gatekeeper + Optimizer) |

> **Nota:** Si Qdrant u OpenRouter no aparecen como tags en lablab.ai, no es bloqueante — están detallados en la Long Description. Lo crítico es que `amd` y `AMD Developer Cloud` queden marcados para que el filtro de la track te encuentre.

---

## Stack técnico completo (para la descripción o preguntas de jueces)

| Capa | Tecnología |
|------|-----------|
| Backend | PHP 8.x, Laravel 11+ |
| Base de datos relacional | PostgreSQL |
| Base de datos vectorial | Qdrant (2048 dims, Named Vectors) |
| Admin panel | Filament |
| LLM Gatekeeper | Llama 3.1-8B (extracción estructurada) |
| LLM Optimizer | Qwen3-VL-32B en AMD MI300X |
| Embeddings | nvidia/llama-nemotron-embed-vl-1b-v2 |
| Moderación | OpenAI Moderation API |
| Transport | Discord (slash commands, modales, webhooks) |

---

## Pipeline resumido (para demos o diagramas)

```
Usuario Discord
    │
    ▼ /registro (modal)
[GatekeeperAgent — Llama 3.1-8B]
  Texto libre → JSON estructurado (campos del perfil)
    │
    ▼ IndexAvatarJob (cola async)
[ContextOptimizerAgent — Qwen3-VL-32B @ AMD MI300X]
  JSON semántico → optimized_text_en + semantic_tag_query
    │
    ▼
[EmbeddingGateway — llama-nemotron-embed]
  Texto → vector float[2048]
    │
    ▼
[Qdrant — matchmaking_hub]
  Upsert con payload: guild_id, red_lines[], archetype_id
    │
    ▼ /buscar-pareja
[MatchmakingService]
  Cosine search + hard filters (red_lines, guild_id) + soft scoring
    │
    ▼ Resultados rankeados en Discord
```
