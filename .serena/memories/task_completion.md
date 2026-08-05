# Task Completion — db2-driver

Pflichtschritte nach jedem Code-Edit (in dieser Reihenfolge):

1. **Format-Check:** `vendor/bin/pint --test <geänderte-Datei>`
2. **Statische Analyse:** `vendor/bin/phpstan analyse <geänderte-Datei>`
3. **Tests:** `vendor/bin/pest` (oder gezielt `--filter=<Name>`)

Alle drei müssen grün sein. Schlägt ein Schritt fehl → fixen, dann wiederholen.

Falls `phpstan.neon` fehlt: Schritt als "nicht verfügbar" reporten — nicht überspringen ohne Hinweis.

## Ausgangslage

Alle drei Checks sind aktuell grün (Pint, PHPStan 0 Fehler, 102 Pest-Tests). Wenn ein Check
nach einem Edit rot wird, ist die Ursache der Edit — nicht Altlast.

## Scope-Disziplin

Vor jedem Feature-Edit prüfen, ob die Fähigkeit im Original-Paket existierte
(`mem:architecture/decisions`, Abschnitt „Scope-Grenze"). Neue Fähigkeiten, die gegen
IBM-i-Systemkatalog-Views arbeiten, gehören erst ins Paket, wenn ein Integrationstest gegen
ein echtes IBM i grün ist. Bewusst nicht implementierte Features sind in
`mem:architecture/findings` und im README unter „Limitations" gelistet — dort nachsehen,
bevor etwas als „Bug" behandelt wird.

## Test-Fallen

- `mockery` ist nur transitiv installiert, nicht deklariert → nicht verwenden.
  Muster für Test-Doubles: `mem:tech_stack`, Abschnitt „Test-Helper".
- Globale Pest-Helper-Funktionen teilen einen Namensraum über alle Testdateien →
  eindeutige Namen wählen, sonst „Cannot redeclare".
- Fehlende Grammar-Methoden werfen `BadMethodCallException` (Macroable), nicht `Error`.
