# Auditor Checklist — MUDRAIS
> Cargado por @auditor al iniciar una revisión. Contiene los 9 pilares con comandos de inspección y fixes tipo.

---

## Pilar 1 — DDD: Aislamiento de Capas

**FAIL si:**
- `Domain/` importa Eloquent, Infrastructure o clientes externos.
- `Application/` importa implementaciones concretas de Infrastructure.
- `Models/` Eloquent contiene lógica de negocio (validaciones, invariantes).

```bash
grep -rn "use Illuminate\|Model::\|DB::\|Eloquent" laravel_app/app/Domain/ 2>/dev/null
grep -rn "use App\\\\Infrastructure\\\\" laravel_app/app/Application/ 2>/dev/null | grep -v "Interface\|Contract"
grep -rn "if.*throw\|invariant\|validate\|DomainException" laravel_app/app/Models/ 2>/dev/null
```

**Fix tipo:**
```php
// VIOLACIÓN: use Illuminate\Database\Eloquent\Model en Domain/
// FIX: La entidad no extiende Model. Persistencia delegada a Infrastructure/Persistence/
final class Transaction { public function __construct(private readonly TransactionId $id) {} }
```

---

## Pilar 2 — SOLID: DI y SRP

**FAIL si:**
- Use Cases / Services instancian repositorios directamente (`new EloquentX`).
- Clases > 150 líneas sin justificación clara.
- Interfaces > 6 métodos.

```bash
grep -rn "= new Eloquent\|= new .*Repository\|new .*Gateway(" laravel_app/app/Application/ 2>/dev/null
find laravel_app/app/Application laravel_app/app/Domain -name "*.php" | xargs wc -l 2>/dev/null | sort -rn | head -15
```

**Fix tipo:**
```php
// VIOLACIÓN: $this->repo = new EloquentPlayerRepository();
// FIX:
public function __construct(private readonly PlayerRepositoryInterface $repo) {}
```

---

## Pilar 3 — Repository/Service Pattern

**FAIL si:**
- Eloquent (`::where`, `::find`, `::create`, `DB::`) aparece fuera de `Infrastructure/Persistence/` y `Models/`.
- Jobs contienen lógica de negocio inline (> 30 líneas en `handle()` sin delegar a UseCase).

```bash
grep -rn "::where\|::find\|::create\|->save()\|DB::table" \
  laravel_app/app/Application/ laravel_app/app/Domain/ laravel_app/app/Jobs/ 2>/dev/null | grep -v vendor
```

**Fix tipo:**
```php
// VIOLACIÓN en UseCase: Player::where('discord_id', $id)->first();
// FIX: $player = $this->playerRepository->findByDiscordId($id);
```

---

## Pilar 4 — TDD: Tests Primero

**FAIL si:**
- Tests no pasan al 100% (NO-GO automático).
- No existen tests para el módulo auditado.

**WARN si:**
- Naming convention no seguida: `test_should[X]_when[Y]`.
- Unit tests llaman a la BD directamente (deben usar mocks).

```bash
cd laravel_app && ./vendor/bin/sail test --stop-on-failure 2>/dev/null | tail -20
find laravel_app/tests -name "*Test.php" 2>/dev/null | xargs grep -l "$(basename $AUDITED_MODULE 2>/dev/null)" 2>/dev/null
grep -rn "public function test" laravel_app/tests/ 2>/dev/null | grep -v "test_should\|test_it\|test_a" | head -10
```

**Fix tipo:**
```php
// VIOLACIÓN: public function testTransaction()
// FIX:
/** @test */
public function test_should_deduct_mudrais_when_transaction_is_processed(): void {}
```

---

## Pilar 5 — i18n: Textos Discord via `__()`

**FAIL si:**
- Strings en español/inglés hardcodeados en controladores, Jobs, Embeds o Modals.
- Claves en `lang/es/discord.php` sin equivalente en `lang/en/discord.php` (o viceversa).

```bash
grep -rn "'[A-ZÁÉÍÓÚ][a-záéíóú ]\{3,\}'\|\"[A-ZÁÉÍÓÚ][a-záéíóú ]\{3,\}\"" \
  laravel_app/app/Http/ laravel_app/app/Jobs/ laravel_app/app/Discord/ 2>/dev/null \
  | grep -v "__(\|//\|Log::" | head -20

diff <(grep -o "'[a-z_]*'" laravel_app/lang/es/discord.php 2>/dev/null | sort) \
     <(grep -o "'[a-z_]*'" laravel_app/lang/en/discord.php 2>/dev/null | sort)
```

**Fix tipo:**
```php
// VIOLACIÓN: 'content' => '⚡ No tienes suficiente energía.'
// FIX:
// lang/es/discord.php: 'economy_insufficient_energy' => '⚡ No tienes suficiente energía.'
// lang/en/discord.php: 'economy_insufficient_energy' => '⚡ You don\'t have enough energy.'
// Código: 'content' => __('discord.economy_insufficient_energy')
```

---

## Pilar 6 — Logging: Observabilidad

**FAIL si:**
- `dd(`, `var_dump(`, `print_r(` en código de producción (fuera de `tests/`).
- Tokens JWT, api_keys o passwords completos en logs.

**WARN si:**
- Jobs o handlers sin ningún `Log::` en `handle()`.
- Canal incorrecto (Discord → `Log::channel('discord')`, no `Log::info()` genérico).

```bash
grep -rn "dd(\|var_dump(\|print_r(\|dump(\|ray(" laravel_app/app/ 2>/dev/null | grep -v "vendor\|//\|*"
grep -rn "Log::.*token\|Log::.*password\|Log::.*api_key" laravel_app/app/ 2>/dev/null | grep -v vendor
for f in laravel_app/app/Jobs/**/*.php laravel_app/app/Jobs/*.php; do
  [ -f "$f" ] && ! grep -q "Log::" "$f" && echo "SIN LOGS: $f"
done 2>/dev/null
```

**Fix tipo:**
```php
// VIOLACIÓN: handle() sin logs
// FIX mínimo:
Log::debug('[ProcessTransactionJob@handle] Iniciando', ['player_id' => $this->playerId]);
// ... lógica ...
Log::info('[ProcessTransactionJob@handle] Completado', ['transaction_id' => $result->id]);
```

---

## Pilar 7 — PSR-12 + PHPDoc

**WARN si:**
- Clases públicas en Domain/ o Application/ sin bloque PHPDoc.
- Métodos públicos sin `@param`, `@return`, `@throws`.

```bash
# Métodos públicos — revisar manualmente si tienen /** */ antes
grep -n "public function" laravel_app/app/Domain/ laravel_app/app/Application/ -r 2>/dev/null | grep -v vendor | head -30
```

**Fix tipo:**
```php
// VIOLACIÓN: public function execute(ProcessTransactionCommand $command): TransactionResult
// FIX:
/**
 * @param ProcessTransactionCommand $command
 * @return TransactionResult
 * @throws InsufficientBalanceException
 */
public function execute(ProcessTransactionCommand $command): TransactionResult
```

---

## Pilar 8 — Jobs Proactivos: Locale

**FAIL si:**
- Job que envía mensajes Discord (sin request HTTP activo) no llama `App::setLocale()` al inicio del `handle()`.

**FAIL si:**
- Controladores llaman `App::setLocale()` directamente (debe hacerlo el middleware `SetDiscordLocale`).

```bash
for f in laravel_app/app/Jobs/**/*.php laravel_app/app/Jobs/*.php; do
  if [ -f "$f" ] && grep -q "discord\|respond\|reply\|channel\|message" "$f" 2>/dev/null; then
    if ! grep -q "App::setLocale\|setLocale" "$f"; then
      echo "SIN LOCALE: $f"
    fi
  fi
done 2>/dev/null
grep -rn "App::setLocale" laravel_app/app/Http/Controllers/ 2>/dev/null
```

**Fix tipo:**
```php
// VIOLACIÓN: handle() sin setLocale en Job proactivo
// FIX:
public function handle(): void
{
    App::setLocale($this->player->preferred_locale ?? 'es');
    // ...
}
```

---

## Pilar 9 — Sin Debug en Producción

**FAIL si:**
- `dd(`, `dump(`, `var_dump(`, `print_r(`, `ray(` en `app/` fuera de `tests/`.

**WARN si:**
- Comentarios `// TODO: remove`, `// DEBUG`, `// FIXME: temp` en archivos de producción.

```bash
grep -rn "dd(\|var_dump(\|print_r(\|dump(\|ray(" laravel_app/app/ 2>/dev/null | grep -v "vendor\|//\|#\|\*"
grep -rn "TODO.*remove\|DEBUG\|FIXME.*temp" laravel_app/app/ 2>/dev/null | grep -v vendor | head -10
```

**Fix:** Eliminar o convertir a `Log::debug()` con canal apropiado.

---

## Formato del Reporte Final

```
═══════════════════════════════════════════════════════════════
MUDRAIS AUDIT — [Contexto] — [Fecha]
═══════════════════════════════════════════════════════════════
VEREDICTO: GO ✅ / NO-GO ❌ / GO CON ADVERTENCIAS ⚠️

┌──────────────────────────────────────┬────────┬──────────────┐
│ Pilar                                │ Estado │ Violaciones  │
├──────────────────────────────────────┼────────┼──────────────┤
│ 1. DDD: Aislamiento de capas         │ PASS ✅ │ 0            │
│ 2. SOLID: DI y SRP                   │ FAIL ❌ │ 2            │
│ 3. Repository/Service Pattern        │ PASS ✅ │ 0            │
│ 4. TDD: Tests primero                │ PASS ✅ │ 0            │
│ 5. i18n: Textos Discord via __()     │ FAIL ❌ │ 3            │
│ 6. Logging: Observabilidad           │ WARN ⚠️ │ 1            │
│ 7. PSR-12 + PHPDoc                   │ WARN ⚠️ │ 2            │
│ 8. Jobs: Locale correcto             │ PASS ✅ │ 0            │
│ 9. Sin debug en producción           │ PASS ✅ │ 0            │
└──────────────────────────────────────┴────────┴──────────────┘

DETALLE DE VIOLACIONES
[FAIL] Pilar 2 — archivo:línea → descripción → fix propuesto
[FAIL] Pilar 5 — archivo:línea → descripción → fix propuesto
[WARN] Pilar 6 — archivo:línea → descripción → fix propuesto
═══════════════════════════════════════════════════════════════
```
