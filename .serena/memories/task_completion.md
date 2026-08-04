# Task Completion — db2-driver

Pflichtschritte nach jedem Code-Edit (in dieser Reihenfolge):

1. **Format-Check:** `vendor/bin/pint --test <geänderte-Datei>`
2. **Statische Analyse:** `vendor/bin/phpstan analyse <geänderte-Datei>`
3. **Tests:** `vendor/bin/pest` (oder gezielt `--filter=<Name>`)

Alle drei müssen grün sein. Schlägt ein Schritt fehl → fixen, dann wiederholen.

Falls `phpstan.neon` fehlt: Schritt als "nicht verfügbar" reporten — nicht überspringen ohne Hinweis.
