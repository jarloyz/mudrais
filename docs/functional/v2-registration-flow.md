# V2 — Zero-Friction Registration Flow

**Version:** 2.0 (AI Agent Olympics hackathon)
**Status:** Implemented

---

## Overview

In v1, unregistered players who used any command other than `/registro` received a plain
error message: *"You are not registered in MUDRAIS. Use `/registro` to begin."*

In v2, the system detects any unregistered player regardless of the command they typed and
automatically starts the `/registro` flow. The user never needs to know they typed the
wrong command.

---

## Behavior by user type

| User type | Action | System response |
|-----------|--------|-----------------|
| **Server admin (new guild)** | Installs bot | Guild auto-created via `GuildValidationService::findOrRegister()` on first interaction |
| **Unregistered player** | Uses **any** slash command | Auto-redirected to `/registro` intro embed |
| **Unregistered player** | Uses `/registro` | Normal flow (unchanged) |
| **Registered player** | Uses any command | Normal command execution (unchanged) |
| **Any user** | Interacts with button / modal (type 3, 5) | Passes through without re-validation (flow already initiated) |

---

## Implementation

### Middleware — `EnsureDiscordCommandPermission`

When a player is not found in the database and the command is not in
`config('historia.discord_public_commands')`, instead of returning a JSON error response
the middleware sets a request attribute and lets the request continue:

```php
$request->attributes->set('force_registro', true);
return $next($request);
```

Ping (type 1), component (type 3), and modal (type 5) interactions bypass this check
entirely — their flow was already authenticated when it started.

### Controller — `DiscordController@handle`

Before the main command dispatch `match`, the controller checks for the flag:

```php
if ($type === 2 && $request->attributes->get('force_registro')) {
    return $this->handleRegistroCommand($interaction, $token);
}
```

This intercepts the interaction before any command-specific handler runs. The original
command name is discarded. The player sees the same registration intro embed they would
see if they had typed `/registro` directly.

---

## Registration flow (unchanged from v1)

Once the player is redirected to `handleRegistroCommand`, the existing state machine runs:

```
handleRegistroCommand()
    │
    ├─ Player not found → RegistroEmbeds::introNuevo()
    │      Green embed + gender selector buttons
    │      → btn_reg_hombre / btn_reg_mujer / btn_reg_otro
    │      → handleSeleccionGenero()
    │      → handleAbrirModal1Nuevo() → modal step 1 (type:9)
    │
    ├─ Player found, no archetype profile → RegistroEmbeds::introCompletarArquetipo()
    │      Skip step 1, go directly to step 2
    │
    └─ Player found, complete → cost check → RegistroEmbeds::introEdicion()
```

---

## Files

| File | Change |
|------|--------|
| `app/Http/Middleware/EnsureDiscordCommandPermission.php` | Sets `force_registro` attribute instead of returning error JSON |
| `app/Http/Controllers/Api/DiscordController.php` | Checks `force_registro` before command dispatch `match` |

---

## What is NOT changed

- Guild registration still requires the server admin to act (bot installation triggers
  `GuildValidationService::findOrRegister()` automatically on the first interaction).
- The registration modal flow itself (steps 1 and 2, jobs, embedding pipeline) is identical to v1.
- Role-based permission checks still apply after a player is registered.
- The archetype profile check for vault-specific channels is unchanged.
