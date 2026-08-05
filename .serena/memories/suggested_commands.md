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
Hinweis: `phpstan.neon` und `phpstan-baseline.neon` sind **versioniert** (nicht gitignored). Die Baseline darf nur schrumpfen, nie wachsen.

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
