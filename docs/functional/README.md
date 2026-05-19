# MUDRAIS — Documentación Funcional

Referencia técnica completa del sistema. Cada documento tiene un propósito específico.
Leer en el orden sugerido si es tu primera vez.

---

## Índice

| Documento | Audiencia | Propósito |
|-----------|-----------|-----------|
| [architecture.md](architecture.md) | Dev / Arquitecto | Stack, DDD, modelos, bounded contexts, glosario |
| [archetype-setup.md](archetype-setup.md) | Admin / Dev | Guía completa para crear un archetype desde cero en Filament |
| [prompt-configuration.md](prompt-configuration.md) | Admin / Dev | Cómo configurar, mantener y depurar prompts de IA por archetype |
| [prompt-flow.md](prompt-flow.md) | Dev | Todos los pipelines de IA: orígenes, placeholders, agentes involucrados |
| [discord-commands.md](discord-commands.md) | Dev / QA | Referencia completa de slash commands: payloads, respuestas, jobs |
| [queue-workers.md](queue-workers.md) | Dev / DevOps | Configuración de workers: Docker, VPS + Supervisor, Shared Hosting + Cron |
| [v2-registration-flow.md](v2-registration-flow.md) | Dev | V2: auto-redirect to /registro for any unregistered player on any command |

---

## Documentos de usuario (para compartir)

| Documento | Idioma |
|-----------|--------|
| [../user-guide-es.md](../user-guide-es.md) | Español — guía amigable para players y admins |
| [../user-guide-en.md](../user-guide-en.md) | English — friendly guide for players and admins |

---

## Documentos de plans y referencia histórica

Los documentos en `docs/plans/` son propuestas de diseño que pueden estar
parcialmente implementadas o haber evolucionado. No se consideran fuente de verdad.

Los documentos en `docs/obsolete/` corresponden a versiones anteriores de la
arquitectura. Se conservan solo como referencia histórica.

---

## Lecturas rápidas por rol

**Quiero crear un nuevo archetype (comunidad)**
→ [archetype-setup.md](archetype-setup.md)

**El LLM está fallando o devolviendo datos incorrectos**
→ [prompt-flow.md](prompt-flow.md) — sección "Problemas conocidos"
→ [prompt-configuration.md](prompt-configuration.md) — sección "Diagnóstico rápido"

**Quiero entender cómo funciona la arquitectura general**
→ [architecture.md](architecture.md) — secciones 3, 4, 6

**Quiero entender qué hace cada comando Discord**
→ [discord-commands.md](discord-commands.md)

**Quiero configurar los prompts de IA de un archetype existente**
→ [prompt-configuration.md](prompt-configuration.md) — secciones 3–6
