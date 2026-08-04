# Conventions — db2-driver

## Namespace & Autoloading
- Produktiv: `Beryildiz\DB2Driver\` → `src/`
- Tests: `Beryildiz\DB2Driver\Tests\` → `tests/`
- PSR-4

## Code-Stil
- Laravel Pint (PSR-12-Basis)
- Keine Alias-Imports ohne Grund; vollständige Typangaben bevorzugen

## Paket-Konventionen
- Service Provider registriert sich via `extra.laravel.providers` in composer.json (Auto-Discovery)
- Zwei Provider: `DB2DriverServiceProvider` (spatie-basiert) und `DB2ServiceProvider`

## Wichtige Einschränkungen
- `composer.lock` ist **gitignored** (Library-Paket — kein Lock-File committen)
- `phpstan.neon`, `phpstan-baseline.neon` und `pint.json` sind **versioniert** (CI-Reproduzierbarkeit,
  seit Phase-0-CI-Fundament — vorher gitignored, das war ein Fehler)
- `testbench.yaml` existiert nicht und wird nicht benötigt — `tests/TestCase.php::getEnvironmentSetUp()` reicht aus
