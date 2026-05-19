# MUDRAIS — AI Agent Olympics Submission
## Milan AI Week 2026

---

## Submission Title

```
MUDRAIS Weaver & Voice: Multimodal Agentic Onboarding for Semantic Matchmaking
```

---

## Short Description

```
MUDRAIS eliminates static onboarding forms through two agentic abstraction layers:
Weaver (conversational AI agent) and Voice (real-time multimodal transcription via
Speechmatics). Both feed a model-agnostic semantic matchmaking engine — routing through
OpenRouter, indexing into Qdrant, and deployed on Vultr's high-performance infrastructure.
```

---

## The Problem

The biggest friction point in any community or B2B platform is the initial data collection step. Static forms and rigid modals destroy user retention. The data they produce is shallow — it reflects what users *think* they want, not the underlying narrative and psychological signals that actually drive compatibility.

MUDRAIS solves this at the architecture level.

---

## The Solution: Two Abstraction Layers

### Layer 1 — MUDRAIS Weaver

Weaver is a conversational AI agent that replaces modals entirely. Through fluid chat, the agent:

1. Extracts structured metadata from natural language responses.
2. Evaluates user context and narrative intent in real time.
3. Dynamically builds a validated JSON schema in the background.
4. Passes that schema to the semantic pipeline for indexing.

A cold interrogation becomes a warm, human onboarding experience — and the output is *richer* than any form could produce.

### Layer 2 — MUDRAIS Voice

Voice is the real disruption. The user joins a call or sends a voice note and **speaks freely** — about their profile, interests, archetype, or narrative preferences. No keyboard. No form. No friction.

The pipeline:

1. **Speechmatics API** transcribes the audio in real time with ultra-high fidelity.
2. The raw transcript enters the AI orchestrator (via OpenRouter).
3. The orchestrator reasons over the response, extracts psychological and narrative variables, and generates a fully structured profile.
4. That profile is vectorized (2048-dim) and indexed into Qdrant instantly.

The user never touches the keyboard. The result is a semantically richer profile than any manually filled form would produce.

---

## Architecture

```
┌─────────────────── Discord ──────────────────────────────────────┐
│                                                                   │
│  Text input            Voice call / Voice note                   │
│       │                        │                                  │
│       ▼                        ▼                                  │
│  [Slash Command]    [voice-bridge — Node.js container]           │
│       │              Speechmatics real-time transcription        │
│       │                        │                                  │
└───────┼────────────────────────┼──────────────────────────────────┘
        │                        │
        └──────────┬─────────────┘
                   ▼
        [Laravel Horizon — Redis queue]
        Async job orchestration
                   │
                   ▼
        [MUDRAIS Weaver Agent]
        OpenRouter → Gatekeeper LLM (20B)
        Natural language → structured JSON schema
                   │
                   ▼
        [Context Optimizer Agent]
        OpenRouter → Reasoning LLM (120B)
        JSON → semantic_text + embedding_query
                   │
                   ▼
        [EmbeddingGateway]
        nvidia/llama-nemotron-embed → float[2048]
                   │
                   ▼
        [Qdrant — matchmaking_hub]
        Named Vectors + guild_id payload isolation
                   │
                   ▼
        [MatchmakingService]
        Cosine search + hard filters (red_lines, guild_id)
        + soft scoring (yellow_lines, timezone)
                   │
                   ▼
        Ranked compatibility results → Discord
```

**Infrastructure:** Fully Dockerized and deployed on **Vultr** high-performance cloud. The entire stack runs in containers: Laravel + PHP 8.3, PostgreSQL, Redis, Qdrant, and the Node.js voice-bridge — orchestrated via Docker Compose with Supervisor managing Horizon workers and Discord gateway processes.

**Model-agnostic by design:** The AI layer routes through **OpenRouter**, allowing seamless swapping between open-source or proprietary models without touching domain code. The matchmaking engine, vector pipeline, and transport layer are fully decoupled.

---

## Technical Stack

| Layer | Technology |
|---|---|
| Backend Framework | PHP 8.3 / Laravel 11 (DDD architecture) |
| Queue & Workers | Laravel Horizon + Redis |
| Voice Transcription | Speechmatics API (real-time, high-fidelity) |
| Voice Gateway | Node.js (`voice-bridge` container) |
| AI Orchestration | OpenRouter (model-agnostic gateway) |
| LLM Gatekeeper | OSS 20B — structured extraction from natural language |
| LLM Optimizer | OSS 120B — semantic expansion, psychological variable extraction |
| Embeddings | nvidia/llama-nemotron-embed-vl-1b-v2 (2048-dim) |
| Vector Database | Qdrant (Named Vectors, payload filtering, guild-level tenant isolation) |
| Relational Database | PostgreSQL |
| Admin Interface | Filament (multi-panel: Admin + Player) |
| Transport Layer | Discord (slash commands, modals, webhooks, voice gateway) |
| Infrastructure | Vultr — Dockerized, Docker Compose + Supervisor |

---

## Why This Matters Beyond Role-Playing Games

MUDRAIS Voice and Weaver are not vertical tools for a gaming niche. They are a **scalable proof of concept** demonstrating how three technology layers — asynchronous agentic data ingestion, multimodal AI, and vector databases — can fully automate user profiling and semantic matching for any community or B2B platform.

The same architecture can power:
- Talent matching platforms (candidates speak freely about their expertise)
- B2B partner discovery (companies describe their needs in a call)
- Therapeutic or coaching platforms (users express preferences verbally)
- Any domain where free-form human expression contains richer signal than form fields

The Discord transport layer is replaceable. The semantic pipeline is the product.

---

## Track

**AI Agents and Agentic Workflows**

The core system is a multi-agent pipeline: `Weaver Gatekeeper → Context Optimizer → EmbeddingGateway → Qdrant`, each agent with an isolated prompt, a single responsibility, and zero coupling to the transport layer.

---

*Submitted to: AI Agent Olympics — The Official Hackathon at Milan AI Week 2026*
