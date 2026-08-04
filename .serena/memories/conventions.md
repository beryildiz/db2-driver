# Conventions — db2-driver

## Namespace & Autoloading
- Produktiv: `BWICompanies\DB2Driver\` → `src/`
- Tests: `BWICompanies\DB2Driver\Tests\` → `tests/`
- PSR-4

## Code-Stil
- Laravel Pint (PSR-12-Basis)
- Keine Alias-Imports ohne Grund; vollständige Typangaben bevorzugen

## Paket-Konventionen
- Service Provider registriert sich via `extra.laravel.providers` in composer.json (Auto-Discovery)
- Zwei Provider: `DB2DriverServiceProvider` (spatie-basiert) und `DB2ServiceProvider`

## Wichtige Einschränkungen
- `composer.lock` ist **gitignored** (Library-Paket — kein Lock-File committen)
- `phpstan.neon` ist **gitignored** — lokal konfigurieren
- `testbench.yaml` ist **gitignored** — lokal konfigurieren
