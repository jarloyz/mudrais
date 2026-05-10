# MUDRAIS — User Guide

Welcome to **MUDRAIS** (Modular Universal Dynamic Roleplay AI System).
This guide explains how the system works, what you can do, and how to get started.

---

## What is MUDRAIS?

MUDRAIS is a Discord bot powered by AI that helps you find **compatible partners for
roleplay, reading, gaming, or any collaborative activity**.

It doesn't use manual filters or keyword matching. It uses semantic vectors — the same
technology behind modern search engines — to understand your style, your preferences,
and what you're looking for, then compares them against every other member of the server.

**What can it do?**
- Find a roleplay partner who writes exactly the way you like
- Find someone who wants to read the same books as you
- Find a compatible gaming teammate at your level and platform
- Post an activity (session, book club, project) and let the system send you the most compatible players

---

## How does it work? (simple version)

1. **You register** — Tell the bot what kind of player/reader you are, in plain text.
   The bot analyzes it with AI and creates your "semantic fingerprint".

2. **You create a context** — Describe your character, your favorite book, your gamer profile.
   This is your "context" (also called an Avatar in the system).

3. **You search or post an activity** — You can search for someone compatible with you,
   or post a search so others can find you.

4. **The engine makes the match** — MUDRAIS compares semantic vectors and returns
   the most compatible results, with a compatibility percentage.

---

## Getting Started

### Step 1 — Register

```
/registro
```

The bot will open a two-step form:

**Step 1/2:**
- Your age (for content classification)
- Nationality
- Experience level in this type of activity
- Your available schedule
- Response length / participation level (1 to 5)

**Step 2/2:**
- **Absolute limits** — Topics you never want to see in an activity
  *(Ex: "explicit gore, child trauma")*
- **Preferences to avoid** — Things you tolerate but prefer not to encounter
  *(Ex: "excessive romance, slapstick humor")*
- **Your favorites** — Genres, styles, tropes you love
  *(Ex: "epic fantasy, mystery, cosmic horror")*
- **Your style** — How you describe how you play/read/participate, in a few lines
  *(Ex: "third person, psychological drama, slow character development")*
- **Introduction letter** — Tell the community about yourself (emojis, story, links — free form)

The bot will process your profile, translate the relevant fields to English for the vector engine,
and confirm with a private message when it's ready.

> 💡 Tip: Be specific in "Your favorites" and "Your style". The more concrete, the better the matchmaking.

---

### Step 2 — Create your context (character, book, game…)

```
/create type:[entity type]
```

This creates your "context" within the server. Depending on the server type, it could be:
- Your roleplay **character**
- A **book** you want to share or reread with someone
- Your **gamer profile**

The bot will open a form with fields specific to that entity type.
Fill them in with detail — these are the data points the vector engine uses for matchmaking.

> ✅ When you create a context, you are **automatically linked** to it.
> You can immediately use it to search for activities.

---

### Step 3 — Search or post an activity

**Search for an existing activity:**
```
/buscar-actividad
```
Available options:
- `texto` — Describe what kind of activity you're looking for in plain text
  *(Ex: "looking for slow-burn vampire drama, political intrigue")*
- `contexto` — Your character/context to refine the search

The bot responds with the 5 most compatible results, ranked by compatibility percentage.

---

**Post your own search:**
```
/actividad crear contexto_principal:[your context]
```
Options:
- `contexto_principal` — Your main context (autocomplete)
- `contexto_secundario` — Optional second context

The bot will open a modal to add:
- **What are you looking for?** — Short title for your search
- **Extra context** — Schedule, level, additional restrictions (optional)

Your activity will be published and visible to other players using `/buscar-actividad`.

---

### Step 4 — Find community members (not activities)

```
/buscar-partner
```

Searches for **players** compatible with you (not posted activities), using your semantic
profile fingerprint. Useful for forming stable teams or finding regular partners.

---

## Plain text character sheet

If your server supports the MUDRAIS character sheet format, you can paste your full sheet:

```
/ficha
```

The bot will open a text area where you can paste your sheet in free form.
The AI will analyze it and automatically extract all relevant fields.

---

## Your current status

```
/status
```

Shows your current status: coins, energy, completed profile, and tutorial progress.

---

## Vault System (narrative worlds)

In roleplay servers, **Vaults** are the narrative worlds or settings.
Each Vault has its own channel and space for characters and activities.

**Create a Vault:**
```
/create_vault archetype:[community type]
```

The bot will automatically create:
- Vault main channel
- Context forum (characters, locations, etc.)
- Activity forum (open sessions)

> Only admins and Game Masters can create Vaults. Players create contexts
> and activities within existing Vaults.

---

## Commands — Quick Reference

| Command | Purpose |
|---------|---------|
| `/registro` | Create or edit your player profile |
| `/ficha` | Paste your character sheet as free text for AI processing |
| `/create type:[type]` | Create a context (character, book, gamer profile…) |
| `/actividad crear` | Post an activity/session search |
| `/buscar-actividad` | Find compatible activities in the server |
| `/buscar-partner` | Find compatible players |
| `/status` | View your status: coins, energy, profile |
| `/create_vault archetype:[type]` | Create a narrative world *(admins only)* |

---

## Frequently Asked Questions

**What language should I write my profile in?**
Any language you want. The bot automatically translates the relevant fields to English
for the search engine. Your introduction letter stays in your original language.

**How long does matchmaking take?**
Searches are nearly instant. The initial processing of your profile (when you first register)
may take a few seconds while the AI analyzes your text and generates your semantic vector.

**Why isn't the compatibility percentage 100% with someone who seems perfect?**
The engine compares many dimensions simultaneously — writing style, genre, pacing,
power dynamics, narrative preferences. A 70%+ score is already very high compatibility.
100% is mathematically impossible (it would require two people with identical vectors).

**Can I edit my profile?**
Yes, use `/registro` again. It may have a coin cost if you've already completed
the tutorial (edit price is configurable per server).

**Are my absolute limits (red lines) visible to other players?**
No. The system uses them internally to filter results — they are never shown
in your public profile. Only system administrators can access them.

**What if I don't fill in all the fields?**
The bot tries to fill empty fields using context from what you did write.
But the more detail you provide, the more precise the matchmaking will be.

**What is a "semantic vector"?**
Think of it as a point in a very high-dimensional space that represents "who you are"
as a player/reader. When two points are close together in that space, it means
the two players have similar styles, tastes, and preferences — even if they used
completely different words to describe themselves.

---

## For server administrators

If you're an admin of a server that wants to use MUDRAIS:

1. **Invite the bot** to your server (link from the Developer Portal)
2. **Authorize yourself via OAuth** on the MUDRAIS portal to get your API token
3. **Configure your Vault** with `/create_vault`
4. **The archetype** (community type) must already be configured in the system.
   If you want a new archetype (e.g., "Sci-fi book club"), contact the MUDRAIS team
   to configure it in the admin panel.

> Archetypes are configured in the Filament admin panel.
> Each archetype defines the form fields, AI prompts,
> and matchmaking rules specific to your community.

---

## Contact and support

To report issues, suggest improvements, or request a new archetype for your server,
contact the MUDRAIS team through your server's support channel or
directly with the system administrators.
