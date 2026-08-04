# Tech Stack — db2-driver

## Runtime
- PHP ^8.1
- Laravel-kompatibel: ^9 | ^10 | ^11 | ^12
- `illuminate/database`, `illuminate/contracts`, `illuminate/support`
- `spatie/laravel-package-tools` ^1.13

## Dev-Dependencies
- **Test-Runner:** Pest ^1.21 | ^3.7 + `pestphp/pest-plugin-laravel`
- **Testbench:** `orchestra/testbench` ^7.0 | ^10.0
- **Static Analysis:** PHPStan via `vendor/bin/phpstan` (config: `phpstan.neon`, gitignored → lokal anlegen)
- **Formatter:** Laravel Pint via `vendor/bin/pint`
- **PHPUnit:** ^9.5 | ^11.5.3 (Basis für Pest)

## Paket-Manager
- Composer; `composer.lock` ist **gitignored** (Library-Paket)

## Kein Frontend-Stack (reines PHP-Backend-Paket)
