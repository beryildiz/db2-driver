# Suggested Commands — db2-driver

## Tests
```
vendor/bin/pest
vendor/bin/pest --filter=<TestName>
vendor/bin/pest --coverage
```

## Statische Analyse
```
vendor/bin/phpstan analyse
```
Hinweis: `phpstan.neon` ist gitignored — lokal anlegen (z. B. von `phpstan.neon.dist` kopieren, falls vorhanden).

## Code-Formatierung
```
vendor/bin/pint
vendor/bin/pint --test   # nur prüfen, nicht schreiben
```

## Composer Scripts (Kurzform)
```
composer test
composer analyse
composer format
composer test-coverage
```

## Testbench (Package Discovery)
```
php vendor/bin/testbench package:discover --ansi
```
