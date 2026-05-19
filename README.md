# MUDRAIS

**Multi-User Dynamic Roleplay AI System** — a semantic matchmaking engine built on an asynchronous, model-agnostic enterprise architecture. The platform eliminates static onboarding forms through two multimodal abstraction layers: **MUDRAIS Weaver** and **MUDRAIS Voice**.

---

## MUDRAIS Weaver

Weaver is a conversational AI agent that replaces rigid modals and forms entirely. Instead of presenting a static questionnaire, the agent interacts with the user through natural chat — extracting metadata, evaluating context, and dynamically structuring a validated JSON schema in the background. A cold data-collection step becomes a warm, functional onboarding experience.

**How it works:**

```
User types freely in Discord
        │
        ▼ Slash command trigger
[Weaver Agent — Gatekeeper LLM]
  Natural language → structured profile fields (JSON)
        │
        ▼ IndexAvatarJob (async, Redis queue)
[Context Optimizer Agent]
  Profile JSON → semantic_text + embedding query
        │
        ▼
[Qdrant — matchmaking_hub]
  Upsert with Named Vectors (2048-dim) + guild payload filters
```

## MUDRAIS Voice

Voice is the multimodal layer. The user joins a call or sends a voice note and speaks freely — profile, interests, archetype, narrative preferences. No keyboard required.

The pipeline uses **Speechmatics** for ultra-high-fidelity real-time transcription. The raw transcript feeds the AI orchestrator, which reasons over the response, extracts psychological and narrative variables, and produces a fully structured profile vector — all without the user filling a single field.

**How it works:**

```
User speaks in voice call / sends voice note
        │
        ▼ voice-bridge (Node.js + Docker)
[Speechmatics API — real-time transcription]
  Audio → raw text transcript
        │
        ▼ HTTP → Laravel / Redis queue
[Weaver Agent pipeline]
  Transcript → structured JSON → 2048-dim vector → Qdrant
```

The `voice-bridge` service is a standalone Node.js container (see `voice-bridge/`) that connects to Discord's voice gateway, streams audio to Speechmatics, and forwards the transcript to Laravel Horizon for async processing.

---

This project is the backend of Historia Pipeline, migrated to a DDD architecture using Laravel, Filament for administration, and Alpine.js for interactivity. It uses Docker containers via Laravel Sail for local development, ensuring an identical environment across machines and eliminating driver compatibility issues.

## Prerequisites

To run this project you need:
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) or Docker Engine with Docker Compose.
- PHP and Composer (only for the initial dependency installation).
- Node.js and NPM (optional — can also be run through Sail).

## Configuration and Environment Variables

1. Clone the project and navigate to the application folder:
   ```bash
   cd laravel_app
   ```

2. Create the configuration file by copying the example:
   ```bash
   cp .env.example .env
   ```

3. Configure the **Qdrant** (vector database) environment variables in your `.env`. Recommended values for local development:
   ```env
   QDRANT_HOST=localhost
   QDRANT_PORT=6333
   QDRANT_API_KEY=
   QDRANT_COLLECTION_NAME=your_collection
   ```

## Starting the Server (Main Environment)

The entire project is run using **Laravel Sail** to avoid compatibility issues and driver dependencies.

1. Install PHP dependencies using Composer locally, or use a temporary container if you don't have PHP installed:
   ```bash
   composer install
   ```
   *(If you don't have Composer installed locally, you can use a Docker container — see [Sail's documentation](https://laravel.com/docs/sail#installing-composer-dependencies-for-existing-projects))*

2. Start the Docker services (PostgreSQL, Qdrant, Redis, and Ngrok) in the background:
   ```bash
   ./vendor/bin/sail up -d
   ```

3. Generate the application key:
   ```bash
   ./vendor/bin/sail artisan key:generate
   ```

4. Run database migrations and seeders:
   ```bash
   ./vendor/bin/sail artisan migrate --seed
   ```

5. Install Node.js dependencies:
   ```bash
   ./vendor/bin/sail npm install
   ```

## Required Workers

For the ecosystem and pipeline to function correctly in async mode, run the following processes in separate terminals. Always use Sail for these commands:

### 1. Queue Worker (Async Jobs)
Processes background tasks (LLM calls, text processing, etc.):
```bash
./vendor/bin/sail artisan queue:work
```
*Use `queue:listen` in development to automatically pick up code changes without restarting.*

### 2. Frontend Server (Vite)
Compiles and serves assets in real time (TailwindCSS, Alpine.js, Filament scripts):
```bash
./vendor/bin/sail npm run dev
```

### 3. Scheduler (Optional)
If there are tasks that run every minute:
```bash
./vendor/bin/sail artisan schedule:work
```

## Docker Services (compose.yaml)

Running `sail up -d` starts the following services:
- **laravel.test**: Main web server with PHP 8.x + Supervisor (Horizon + Discord gateways).
- **pgsql**: PostgreSQL relational database.
- **qdrant**: Vector database (dashboard at `http://localhost:6333/dashboard`).
- **redis**: Cache and queue backend (used by Horizon and voice-bridge).
- **voice-bridge**: Node.js service that connects to Discord's voice gateway and delegates to Speechmatics + Laravel.
- **ngrok**: Exposes your local project temporarily (development only).

## Documentation

Technical documentation lives in [`docs/functional/`](docs/functional/README.md).

| Document | Purpose |
|---|---|
| [architecture.md](docs/functional/architecture.md) | Stack, DDD, models, bounded contexts, glossary |
| [archetype-setup.md](docs/functional/archetype-setup.md) | Full guide for creating an archetype from scratch in Filament |
| [prompt-configuration.md](docs/functional/prompt-configuration.md) | How to configure, maintain, and debug AI prompts per archetype |
| [prompt-flow.md](docs/functional/prompt-flow.md) | AI pipelines: origins, placeholders, agents involved |
| [discord-commands.md](docs/functional/discord-commands.md) | Slash command reference: payloads, responses, jobs |
| [queue-workers.md](docs/functional/queue-workers.md) | Worker configuration: Docker, VPS + Supervisor, Shared Hosting + Cron |

**User guides**

| Document | Language |
|---|---|
| [user-guide-es.md](docs/user-guide-es.md) | Spanish |
| [user-guide-en.md](docs/user-guide-en.md) | English |

> Documents in `docs/obsolete/` and `docs/plans/` are historical reference and may not be up to date.

## License

Distributed under the [MIT License](LICENSE).

## Notes

- **Artisan commands:** Never run `php artisan ...` directly. Always use `./vendor/bin/sail artisan ...` to avoid database driver or missing PHP extension issues.
- **Project state:** All tasks and progress are tracked in `estado_proyecto.json` by the agents.
- **Access:** The application is available at `http://localhost` and the vector database dashboard at `http://localhost:6333/dashboard`.
