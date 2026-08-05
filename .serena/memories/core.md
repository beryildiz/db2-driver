# Core — db2-driver

Laravel-Paket: DB2/IBM iSeries Drop-in Driver für Laravel.
Namespace: `Beryildiz\DB2Driver\`
Paketname: `beryildiz/db2-driver`

## Source Map

```
src/
  DB2Connection.php          — Eloquent Connection-Implementierung
  DB2Connector.php           — PDO-Connector
  DB2DriverServiceProvider.php
  DB2Processor.php           — Query Processor
  DB2QueryGrammar.php        — SQL-Grammar für DB2
  DB2ServiceProvider.php
  Schema/                    — Schema Builder & Grammar
tests/
  Unit/                      — Grammar/Connector/Processor Unit-Tests (ohne DB)
  TestCase.php               — Orchestra Testbench Basis
  Pest.php                   — globales uses(TestCase::class)
```

## Weiterführende Memories

- Tech Stack, Abhängigkeiten: `mem:tech_stack`
- Dev-Befehle (Test, Lint, Format): `mem:suggested_commands`
- Code-Konventionen: `mem:conventions`
- Task-Abschluss-Checkliste: `mem:task_completion`
