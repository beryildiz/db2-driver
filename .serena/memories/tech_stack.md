# Tech Stack — db2-driver

## Namespace
- Alle Quelldateien: `Beryildiz\DB2Driver` (PSR-4 in composer.json)
- Tests: `Beryildiz\DB2Driver\Tests`
- ACHTUNG: Das `read`-Tool kann veraltete Inhalte zeigen — immer per Shell verifizieren

## Versionen (seit Laravel-12-only Migration)
- PHP `^8.2`, `illuminate/contracts|support|database ^12.0`
- Dev: `orchestra/testbench ^10.0`, `pestphp/pest ^3.7`, `nunomaduro/collision ^8.0`,
  `phpunit/phpunit ^11.5.3`
- `composer.lock` ist gitignored → immer `composer update` (nicht `composer install`) nach Clone

## Verfügbare Verifikationstools
- `vendor/bin/pest` ✅ installiert
- `vendor/bin/pint` ✅ installiert (`laravel/pint ^1.18`, `pint.json` Preset `laravel`, versioniert,
  NICHT gitignored)
- `vendor/bin/phpstan` ✅ installiert (`larastan/larastan ^3.1`, Level 5, `phpstan.neon` inkl.
  `phpstan-baseline.neon` — beide versioniert, NICHT gitignored). Baseline enthält 89 Einträge,
  ausschließlich `property.notFound`/`method.notFound`/`function.impossibleType`/
  `isset.property`/`nullCoalesce.property`/`deadCode.unreachable` auf `Fluent`-Magic-Properties
  bzw. generischen Illuminate-Basisklassen-Typisierungen (`Connection`/`Grammar`/`Processor`/
  `Builder` kennen die konkreten DB2-Subklassen-Methoden nicht). Keine echten Bugs — dokumentiertes
  Problem, siehe `architecture/decisions` → „PHPStan"-Abschnitt. Baseline nur schrumpfen, nicht
  wachsen lassen.
- CI: `.github/workflows/tests.yml` (Matrix PHP 8.2/8.3/8.4 × prefer-lowest/prefer-stable, keine
  Laravel-Version-Dimension mehr — L12-only) und `.github/workflows/static.yml` (Pint + PHPStan,
  PHP 8.3).
- Serena-LSP-Diagnostics (intelephense) sind in dieser Umgebung **unzuverlässig/stale** für
  `vendor/`-Symbole (z.B. hält an alten Blueprint/Grammar-Signaturen fest, auch nach
  `composer update` + mehrfachem Retry). Bei Verdacht auf Signatur-Inkompatibilität IMMER
  zusätzlich mit einem kleinen PHP-Skript verifizieren (Klassen instanziieren + Reflection-Aufrufe,
  Fatal Errors sind ground truth), nicht blind der LSP-Diagnose vertrauen.

## Test-Helper (tests/Pest.php)
- Globale Funktion `makeConnection(): DB2Connection` — liefert eine minimale DB2Connection mit
  No-Op-PDO-Closure + `useDefaultSchemaGrammar()`. Nötig, weil Laravel 12 `Connection` im
  Blueprint-/Grammar-Konstruktor verlangt (`Illuminate\Database\Grammar::__construct(Connection $connection)`,
  `Schema\Blueprint::__construct(Connection $connection, $table, ?Closure $callback = null)`).
  Immer diesen Helper nutzen statt `new DB2QueryGrammar()`/`new DB2SchemaGrammar()`/
  `new Blueprint($table)` ohne Connection — das ist seit L12 ein `ArgumentCountError`.

## Testbench-Cache
- Cache-Datei: `vendor/orchestra/testbench-core/laravel/bootstrap/cache/packages.php`
- Bei Namespace-Änderungen: `php vendor/bin/testbench package:discover --ansi` ausführen

## Klassen-Übersicht
- `DB2QueryGrammar` — Query-Grammatik, `offsetCompatibilityMode=true` per Default
- `DB2SchemaGrammar` — Schema-Grammatik (information_schema-basiert)
- `DB2Connector` — DSN-Aufbau via `getDsn(array $config)`
- `DB2Processor` — `processColumnListing()` gibt Input unverändert zurück
- `DB2ServiceProvider` — registriert Connection-Resolver für 'db2'-Driver
- `DB2DriverServiceProvider` — Spatie PackageServiceProvider
