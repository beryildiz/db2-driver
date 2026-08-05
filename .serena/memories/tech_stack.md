# Tech Stack — db2-driver

## Namespace
- Quelldateien: `Beryildiz\DB2Driver` (PSR-4), Tests: `Beryildiz\DB2Driver\Tests`

## Versionen
- PHP `^8.2`, `illuminate/contracts|support|database ^12.0`
- Dev: `orchestra/testbench ^10.0`, `pestphp/pest ^3.7`, `nunomaduro/collision ^8.0`,
  `phpunit/phpunit ^11.5.3`, `larastan/larastan ^3.1`, `laravel/pint ^1.18`
- `composer.lock` ist gitignored → immer `composer update`, nie `composer install`
  (gilt auch für CI — beide Workflows nutzen inzwischen `composer update`)
- **`mockery` ist nur transitiv installiert, NICHT in composer.json deklariert** — in Tests
  nicht verwenden. Stattdessen anonyme Subklassen (Muster siehe unten).

## Quelldateien

| Datei | Zeilen | Zweck |
|---|---|---|
| `src/DB2Connection.php` | ~125 | Connection: `getDefaultSchema`, `setCurrentSchema`, `resetCurrentSchema`, `executeCommand`, `getSchemaBuilder`, Grammar-/Processor-Defaults. Akzeptiert `PDO\|Closure` (lazy) |
| `src/DB2Connector.php` | 77 | `getDsn()`, `normalizeSchema()` (Validierung + Uppercase, weil `SET SCHEMA` kein Binding verträgt) |
| `src/DB2DriverServiceProvider.php` | ~20 | Spatie PackageServiceProvider, nur `$package->name()` |
| `src/DB2Processor.php` | ~60 | `processInsertGetId()` (case-insensitiv, `select … from new table`), `processColumnListing()` |
| `src/DB2QueryGrammar.php` | ~195 | ANSI-Offset-Mode, `compileSavepoint`, `compileExists`, Datumsformat |
| `src/DB2ServiceProvider.php` | 32 | `db.connector.db2` + Connection-Resolver für 'db2' |
| `src/Schema/DB2Blueprint.php` | ~175 | `synchro`, `numeric`, `boolean`, `forSystemName`, `label`, `indexCommand`, Reply-List |
| `src/Schema/DB2Builder.php` | ~160 | `hasTable`, `getColumnListing`, `parseQualifiedTable`, `build`, `createBlueprint`, Narrowing-Accessoren |
| `src/Schema/DB2SchemaGrammar.php` | ~887 | Schema-Grammar (Kommandos, 20 Spaltentypen, 8 Modifier, QCMDEXC) |

## Verifikation (alle grün, Stand Option-B-Abschluss)

```
vendor/bin/pint --test        # ✅ passed
vendor/bin/phpstan analyse    # ✅ 0 Fehler, Level 5, KEINE Baseline
vendor/bin/pest               # ✅ 102 Tests, 168 Assertions
```

`phpstan.neon` analysiert nur `src`. Zwei Parameter ersetzen die frühere Baseline:
- `universalObjectCratesClasses: [Illuminate\Support\Fluent]` — Fluent ist ein Property-Bag
- `treatPhpDocTypesAsCertain: false` — Illuminates PHPDoc ist zu lax (nullable ohne `?`)

Die Datei ist valides UTF-8; Umlaute erscheinen in der PowerShell-Konsole nur wegen der
Codepage verstümmelt — **nicht "reparieren"**.

## Testdateien

| Datei | Tests |
|---|---|
| `tests/Unit/DB2SchemaGrammarTest.php` | Kommandos, Typen, Modifier, `getDefaultValue`, QCMDEXC-Escaping, nicht unterstützte Typen |
| `tests/Unit/DB2BuilderTest.php` | `hasTable`/`getColumnListing`-Bindings, Prefix-nach-Schema, nicht unterstützte Introspection |
| `tests/Unit/DB2BlueprintTest.php` | `indexCommand`, `synchro`/`dropSynchro`-Symmetrie, Reply-List-Guards |
| `tests/Unit/DB2ProcessorTest.php` | `processInsertGetId` (Case, fehlende Spalte, Array-Sequence) |
| `tests/Unit/DB2ConnectionTest.php` | Schema-Normalisierung, `SET SCHEMA DEFAULT`, Lazy-Closure, Grammar-Auflösung |
| `tests/Unit/DB2QueryGrammarTest.php` | Offset-Mode, Limit/Offset, `compileExists`, Savepoint |
| `tests/Unit/DB2ConnectorTest.php` | DSN-Aufbau, `normalizeSchema` |

## Test-Helper — Muster

**`tests/Pest.php`:** `makeConnection(): DB2Connection` — minimale Connection mit No-Op-Closure
+ `useDefaultSchemaGrammar()`. Immer nutzen statt `new DB2*Grammar()` / `new Blueprint($table)`
ohne Connection — das ist seit L12 ein `ArgumentCountError`.

**Recording-Subklassen statt Mocks.** Beispiel aus `DB2BuilderTest.php`:
`RecordingDB2Connection extends DB2Connection` überschreibt `select()`, schreibt `capturedSql`
und `capturedBindings` mit. Analog in `DB2ProcessorTest`/`DB2ConnectionTest` als anonyme
Klassen. Grund: kein mockery, und `$this->getMockBuilder()` in globalen Pest-Funktionen ist
fragil.

**Achtung Namenskollisionen:** Pest lädt alle Testdateien in denselben Namensraum. Globale
Helper-Funktionen brauchen eindeutige Namen (`makeRecordingQueryBuilder` vs.
`makeQueryBuilder`), sonst „Cannot redeclare".

**Fehlertyp bei fehlenden Grammar-Methoden:** `Illuminate\Database\Grammar` nutzt `Macroable`,
also `BadMethodCallException` — **kein** `Error`.

## Testbench-Cache
`vendor/orchestra/testbench-core/laravel/bootstrap/cache/packages.php`; bei
Namespace-Änderungen `php vendor/bin/testbench package:discover --ansi`.

## Katalog-Views (nur diese beiden werden genutzt)
`information_schema.tables` (`compileTableExists`), `information_schema.columns`
(`compileColumnExists`). Beide binding-basiert. Weitergehende Introspection ist bewusst nicht
implementiert — siehe `mem:architecture/findings`.
