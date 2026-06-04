# Scrum Master Protocols — MUDRAIS
> Cargado por @scrum_master según la acción requerida. Contiene protocolos detallados y formatos.

---

## Estados del Tablero

```
TO DO → IN PROGRESS → REVIEW → DONE
```

| Estado | Límite | Condición de entrada |
|---|---|---|
| `TO DO` | Sin límite | HU-ID válido con archivo aprobado |
| `IN PROGRESS` | **WIP máx. 2** | WIP actual < 2 |
| `REVIEW` | Sin límite | Usuario declara implementación completa |
| `DONE` | Solo con ✅ | GO de @auditor + GO de @guardian (si hay deps nuevas) |

---

## PROTOCOLO: PLANNING

1. Leer `pm/backlog_general.md` → HUs disponibles por prioridad.
2. Leer `estado_proyecto.json` → tareas técnicas pendientes que respaldan las HUs.
3. Verificar que cada HU candidata tiene `status: APPROVED` en `pm/historias_de_usuario/`.
4. Para cada HU que entra: buscar plan asociado en `docs/plans/`. Anotar si existe o indicar "Sin plan — implementación directa".
5. Proponer HUs al usuario con estimación de velocidad.
6. Al confirmar, actualizar sprint_board.md con nuevo Sprint:

```markdown
# Sprint Board — Sprint-N
**Goal:** [Objetivo]
**Fechas:** YYYY-MM-DD → YYYY-MM-DD
**Velocidad objetivo:** N puntos
**WIP actual:** 0 / 2

## TO DO
| HU | Título | Puntos | Plan asociado |
|---|---|---|---|
| HU-NNN | [Título] | N | docs/plans/X.md o "Sin plan" |
```

7. Registrar en historial: `SPRINT_PLANNING | HU-NNN | Sprint-N, N pts, TO DO`

---

## PROTOCOLO: MOVE_TO_IN_PROGRESS

1. Verificar WIP < 2. Si WIP = 2 → WARN (ver plantilla abajo).
2. Mover la HU en sprint_board.md a `IN PROGRESS` con fecha de inicio.
3. Registrar: `STATUS_CHANGED | HU-NNN | TO DO → IN PROGRESS`

**Plantilla WARN WIP:**
```
⚠️ WIP LIMIT ALCANZADO — Sprint-N tiene 2 tareas en IN PROGRESS.
Termina o mueve a REVIEW una de estas antes de iniciar otra:
- HU-XXX: [descripción] (en progreso desde YYYY-MM-DD)
- HU-YYY: [descripción] (en progreso desde YYYY-MM-DD)
El usuario puede hacer override explícito.
```

---

## PROTOCOLO: MOVE_TO_REVIEW

1. Confirmar que el usuario declara la implementación completa.
2. Mover la HU en sprint_board.md a `REVIEW`.
3. Instrucción al usuario:
   ```
   Implementación en REVIEW. Siguiente paso:
   @auditor — revisa el bounded context [NombreContexto]
   ```
   (Si hay deps nuevas añadir: `@guardian — revisa dependencias de HU-NNN`)
4. Registrar: `STATUS_CHANGED | HU-NNN | IN PROGRESS → REVIEW`
5. Actualizar handoff.md con módulo y archivos afectados para el @auditor.

---

## PROTOCOLO: MOVE_TO_DONE

**Verificar ANTES de mover:**
- "¿El @auditor dio GO para HU-NNN?" → Siempre requerido.
- "¿El @guardian dio GO para HU-NNN?" → Solo si se introdujeron deps nuevas.

Si cualquier GO falta → NO-GO (ver plantilla abajo).

Si ambos son GO:
1. Mover a DONE en sprint_board.md con ✅ Auditor GO.
2. **Sincronizar `estado_proyecto.json`**: tareas asociadas → `"terminado"` + `"archivos_relacionados"` completos.
3. Calcular y actualizar métricas del Sprint.
4. Registrar: `STATUS_CHANGED | HU-NNN | REVIEW → DONE, Auditor GO: ✅`
5. Actualizar handoff.md con cierre.

**Plantilla NO-GO DoD:**
```
🔴 NO-GO — Definición de Hecho violada.
HU-NNN no puede pasar a DONE sin el GO de los agentes de calidad.
Flujo correcto:
  1. Mover a REVIEW (ya está)
  2. @auditor — revisa [BoundedContext]         (siempre obligatorio)
  3. @guardian — revisa deps de HU-NNN          (solo si hay deps nuevas)
  4. Con GO de ambos → DONE
```

---

## PROTOCOLO: CHECK_BLOCKERS

Revisar sprint_board.md y reportar con semáforo:

| Condición | Señal |
|---|---|
| WIP = 2 y nueva solicitud | 🟡 WARN — WIP limit |
| Tarea IN PROGRESS > 2 días sin avance | 🟡 WARN — posible bloqueo |
| Tarea en REVIEW > 1 día sin @auditor | 🟡 WARN — invocar @auditor |
| HU sin archivo HU-NNN.md válido | 🔴 NO-GO — trazabilidad violada |
| DONE sin GO registrado | 🔴 NO-GO — DoD violada |

---

## PROTOCOLO: CLOSE_SPRINT

1. Mover tareas NO en DONE → `pm/backlog_general.md` con motivo.
2. Calcular velocidad lograda (suma puntos DONE).
3. Actualizar métricas finales en sprint_board.md.
4. Crear entrada en `pm/retrospectivas.md`:

```markdown
## Retrospectiva Sprint-N
**Fecha de cierre:** YYYY-MM-DD
**Velocidad lograda:** N puntos (objetivo: M)
**HUs completadas:** HU-XXX (N pts)
**HUs al backlog:** HU-ZZZ — [motivo]

### ¿Qué salió bien?
*(completar con el usuario)*

### ¿Qué mejorar?
*(completar con el usuario)*

### Acciones concretas
| Acción | Fecha límite |
|---|---|
```

5. Revisar `docs/plans/` — para cada plan afectado por las HUs cerradas:
   - **COMPLETO ✅** — todas las HUs del plan en DONE.
   - **EN PROGRESO 🔄** — algunas DONE, otras pendientes.
   - **PENDIENTE ⏳** — ninguna HU iniciada.
   - **SIN HU ⚠️** — plan sin HUs → añadir al backlog como deuda de documentación.

6. Verificar `estado_proyecto.json`: todas las tareas de HUs cerradas deben tener `"estado": "terminado"`.
7. Preparar sprint_board.md vacío para el próximo Sprint.
8. Registrar: `SPRINT_CLOSED | Sprint-N | Velocidad: N pts. HUs completadas: X. HUs al backlog: Y.`

---

## Estimación de Story Points

| Criterio | Pregunta |
|---|---|
| Capas DDD | ¿Afecta solo Application o también Domain e Infrastructure? |
| Discord/UI | ¿Nuevos slash commands, embeds, modals, Jobs? |
| i18n | ¿Cuántas claves nuevas de traducción se necesitan? |
| Tests | ¿Cuántos casos de test distintos genera? |
| Deps externas | ¿Necesita Qdrant, OpenRouter, nuevas tablas? |
| Incertidumbre | ¿Algo no probado antes en MUDRAIS? |

Fibonacci: 1, 2, 3, 5, 8, 13. Si > 8 → sugerir dividir antes de planificar.
