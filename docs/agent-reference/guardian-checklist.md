# Guardian Checklist — MUDRAIS
> Cargado por @guardian al auditar una dependencia. Contiene los 4 pilares con métodos de inspección.

El stack de MUDRAIS usa:
- **Composer/Packagist** — `laravel_app/composer.json`
- **npm** — `laravel_app/package.json`

---

## Pilar 1 — Scripts de Ciclo de Vida `[CRÍTICO — NO-GO si positivo]`

**Qué buscar:** Scripts que se ejecutan automáticamente al instalar.

**Para npm:**
```
WebFetch: https://registry.npmjs.org/<nombre>/latest
→ Buscar campo "scripts": { "preinstall", "postinstall", "install" }
Señales de NO-GO:
  - "postinstall": "curl ... | bash"
  - "postinstall": "node ./scripts/telemetry.js"   ← telemetría sin opt-out
  - "install": "python setup.py"
```

**Para Composer/PHP:**
```
WebFetch: https://packagist.org/packages/<vendor>/<nombre>.json
→ Buscar "scripts" en composer.json del paquete
Señales de NO-GO:
  - "post-install-cmd": ["php scripts/telemetry.php"]
  - "post-autoload-dump" ejecutando código arbitrario
```

---

## Pilar 2 — Typosquatting `[CRÍTICO — NO-GO si positivo]`

**Método:** Buscar en registro oficial y verificar que el vendor/nombre coincide exactamente con el canónico.

**Paquetes del stack MUDRAIS — referencias canónicas:**

| Canónico | Vigilar typosquats |
|---|---|
| `laravel/framework` | `larave/framework`, `laravel-framework` |
| `filament/filament` | `filament/filaments`, `filamentphp/filament` |
| `livewire/livewire` | `livewire/livewires`, `live-wire/livewire` |
| `openai/openai-php` | `openai-php/openai`, `open-ai/openai-php` |
| `alpinejs` (npm) | `alpine-js`, `alpinejs2`, `alpinej` |
| `axios` (npm) | `axois`, `axioss`, `axios-http` |
| `qdrant/qdrant-client` | `qdrant-client`, `qdrantclient` |

**Verificación:**
```
1. WebFetch al registro oficial con el nombre exacto propuesto.
2. Comparar vendor + nombre + email del mantenedor con el paquete canónico conocido.
3. Si no coincide → NO-GO.
```

---

## Pilar 3 — Bloat / Dependencias Ocultas `[WARN]`

**Umbral:** > 10 dependencias transitivas para una tarea que MUDRAIS podría resolver con código existente.

**Para npm:**
```
WebFetch: https://bundlephobia.com/package/<nombre>
→ Revisar "dependencyCount" y tamaño en KB
```

**Para Composer:**
```
WebFetch: https://packagist.org/packages/<vendor>/<nombre>.json
→ Revisar campo "require" en el composer.json publicado
```

**Alternativas nativas a buscar primero:**
- Laravel Collections, helpers (`Str::`, `Arr::`)
- Carbon (ya incluido en Laravel)
- Guzzle (ya incluido vía Laravel)
- PHP stdlib: `array_*`, `str_*`, `json_encode/decode`

---

## Pilar 4 — Popularidad y Mantenimiento `[WARN]`

**Inspección GitHub:**
```
WebFetch: https://api.github.com/repos/<owner>/<repo>
→ "stargazers_count": < 100 → WARN
→ "pushed_at": > 12 meses desde hoy → WARN
→ "archived": true → NO-GO (usar Pilar 1 para el NO-GO)
→ "fork": true → verificar si es fork legítimo o typosquat
```

**Excepciones GO a pesar de baja popularidad:**
- Vendor conocido: Laravel, Filament, Anthropic, Qdrant, Discord, etc.
- Fork documentado de librería mayor con justificación clara.

---

## Formato del Reporte de Riesgo

```
═══════════════════════════════════════════════════════════
MUDRAIS GUARDIAN — Reporte de Dependencia
Paquete: <nombre> v<versión>
Ecosistema: Composer / npm
Fecha: YYYY-MM-DD
═══════════════════════════════════════════════════════════
VEREDICTO: GO ✅ / NO-GO ❌ / GO CON ADVERTENCIAS ⚠️

┌────────────────────────────────────┬────────┬──────────────────────┐
│ Pilar                              │ Estado │ Hallazgo             │
├────────────────────────────────────┼────────┼──────────────────────┤
│ 1. Scripts de ciclo de vida        │ PASS ✅ │ Sin postinstall hooks│
│ 2. Typosquatting                   │ PASS ✅ │ Nombre canónico OK   │
│ 3. Bloat / Dependencias ocultas    │ WARN ⚠️ │ 18 deps transitivas  │
│ 4. Popularidad y mantenimiento     │ PASS ✅ │ 8.2k ★, activo       │
└────────────────────────────────────┴────────┴──────────────────────┘

Maintainer: <nombre> (<perfil>)
Publicado:  <fecha última versión>
Repositorio: <URL>

DETALLE
[WARN] Pilar 3 — La librería X trae 18 deps transitivas para [tarea].
  Alternativa: usar [Y ya presente en MUDRAIS / función de N líneas].

DECISIÓN REQUERIDA
  GO ⚠️ → proceder si el usuario justifica. Documentar en PR description.
═══════════════════════════════════════════════════════════
```

---

## Criterio GO / NO-GO

| Condición | Veredicto |
|---|---|
| Script postinstall descarga binario o ejecuta curl/wget | NO-GO ❌ |
| Telemetría sin opt-out en instalación | NO-GO ❌ |
| Typosquat detectado | NO-GO ❌ |
| Repositorio archivado en GitHub | NO-GO ❌ |
| Solo WARNs (bloat, popularidad baja) | GO ⚠️ — el desarrollador justifica |
| Todos PASS | GO ✅ |
