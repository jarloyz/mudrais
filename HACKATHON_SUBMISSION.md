# MUDRAIS — Hackathon Submission (lablab.ai)

## Submission Title

```
MUDRAIS: Semantic Matchmaking Engine
```

---

## Short Description

```
Multi-tenant SaaS that converts Discord user profiles into 2048-dim semantic vectors via AMD-accelerated OSS 120B and Qdrant, enabling privacy-isolated partner matching across roleplay, book clubs, and gaming communities — no custom infrastructure required.
```

---

## Long Description

```
MUDRAIS (Multi-User Dynamic Roleplay AI System) solves a fundamental problem in online
communities: finding truly compatible collaborators. Traditional bots rely on keyword filters
that miss nuance — narrative tone, writing style, thematic compatibility. MUDRAIS replaces
that with a semantic matchmaking engine.

When a user registers, their free-text input enters a multi-agent AI pipeline running on
AMD MI300X via AMD Developer Cloud. A Gatekeeper agent (OSS 20B) extracts structured fields
from natural language. A Context Optimizer agent (OSS 120B) generates a semantically dense
representation tuned for embedding. The nvidia/llama-nemotron-embed-vl-1b-v2 model then
converts it into a 2048-dimensional float vector stored in Qdrant.

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

The core of the system is a multi-agent pipeline: Gatekeeper → ContextOptimizer → Embedding → Qdrant, each agent with an isolated prompt and a single responsibility.

---

## Technologies

| Tag | Reason |
|-----|--------|
| `amd` | Core compute infrastructure |
| `AMD Developer Cloud` | Runs OSS 120B and OSS 20B on AMD MI300X hardware |
| `Qdrant` | Vector database (Named Vectors, 2048 dims, payload filtering) |
| `OpenRouter` | Multi-LLM gateway (Gatekeeper + Optimizer) |

> **Note:** If Qdrant or OpenRouter are not available as tags on lablab.ai, they are detailed in the Long Description. The critical tags are `amd` and `AMD Developer Cloud` — those must be marked so the track filter picks up the submission.

---

## Full Technical Stack (for judges)

| Layer | Technology |
|-------|-----------|
| Compute Infrastructure | AMD Instinct MI300X (AMD Developer Cloud) |
| Backend Framework | PHP 8.x / Laravel 11 |
| Vector Database | Qdrant (Named Vectors, 2048 dims) |
| Relational Database | PostgreSQL |
| LLM Gatekeeper | OSS 20B — structured extraction from natural language |
| LLM Optimizer | OSS 120B — semantic expansion and embedding-ready output |
| Embeddings | nvidia/llama-nemotron-embed-vl-1b-v2 |
| AI Gateway | OpenRouter |
| Admin Interface | Filament |
| Transport | Discord (slash commands, modals, webhooks) |

---

## Pipeline Overview (for demos and diagrams)

```
Discord User
    │
    ▼ /register (modal)
[GatekeeperAgent — OSS 20B @ AMD MI300X]
  Free-text input → structured JSON (profile fields)
    │
    ▼ IndexAvatarJob (async queue)
[ContextOptimizerAgent — OSS 120B @ AMD MI300X]
  Semantic JSON → optimized_text_en + semantic_tag_query
    │
    ▼
[EmbeddingGateway — llama-nemotron-embed]
  Text → float[2048] vector
    │
    ▼
[Qdrant — matchmaking_hub]
  Upsert with payload: guild_id, red_lines[], archetype_id
    │
    ▼ /find-partner
[MatchmakingService]
  Cosine search + hard filters (red_lines, guild_id) + soft scoring
    │
    ▼ Ranked results delivered in Discord
```
