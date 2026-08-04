# Tech Stack — db2-driver

## Namespace
- Alle Quelldateien: `Beryildiz\DB2Driver` (PSR-4 in composer.json)
- Tests: `Beryildiz\DB2Driver\Tests`
- ACHTUNG: Das `read`-Tool kann veraltete Inhalte zeigen — immer per Shell verifizieren

## Verfügbare Verifikationstools
- `vendor/bin/pest` ✅ installiert
- `vendor/bin/pint` ❌ NICHT installiert
- `vendor/bin/phpstan` ❌ NICHT installiert

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
