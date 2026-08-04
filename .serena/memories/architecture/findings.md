# Architektur-Befunde — db2-driver

Ergebnis der Architektur-Analyse. Kritische Funde, die vor jedem Refactoring adressiert werden müssen.

## Status (Stand: Laravel-12-only Migration)

Projekt ist seit der Migration auf **Laravel 12 exklusiv** festgelegt (`composer.json`:
`illuminate/* ^12.0`, `php ^8.2`, `testbench ^10.0`, `pest ^3.7`, kein `minimum-stability: dev`
mehr). V2, V3, V4, V6, V7, V9 sind **behoben** (siehe unten). V1 ist damit strukturell erledigt
(nur noch eine Zielversion). V5/V8/V10 sind weiterhin offen.

## Kritisch (🔴) — behoben

- **V2 — Signatur-Drift war Fatal Error, jetzt gefixt.** Reale L12-Änderungen (verifiziert in
  `vendor/laravel/framework`, nicht nur vermutet):
  - `Schema\Blueprint::__construct(Connection $connection, $table, ?Closure $callback = null)`
    — Connection jetzt im Konstruktor, nicht mehr in `toSql()`/`build()`.
  - `Schema\Blueprint::toSql()` und `::build()` sind **parameterlos** (Connection/Grammar kommen
    aus `$this->connection`/`$this->grammar`, gesetzt im Konstruktor).
  - `Schema\Grammars\Grammar::compile*(Blueprint $blueprint, Fluent $command)` — **kein**
    `Connection`-Parameter mehr. Grammar selbst erbt `Illuminate\Database\Grammar::__construct(Connection $connection)`
    und hat über `$this->connection` (protected, geerbt) Zugriff, falls eine Query nötig ist
    (z.B. `compileAddReplyListEntry`).
  - `Connection::withTablePrefix()` **existiert nicht mehr** — Grammar zieht den Prefix selbst
    via `$this->connection->getTablePrefix()` (auch `$this->tablePrefix`-Property auf Grammar
    ist weg, nur noch `getTablePrefix()`-Methode).
  - `Schema\Builder::createBlueprint()` ruft den Resolver mit `($connection, $table, $callback)`
    auf (Connection zuerst, 3 Argumente) — vorher nur `($table, $callback)`.
  - `Schema\Grammars\Grammar::compileTableExists($schema, $table)` — Basisklasse deklariert diese
    Methode jetzt selbst mit 2 Pflichtparametern (vorher nicht deklariert). Override mit 0 Parametern
    → Fatal Error. Fix: `compileTableExists($schema = null, $table = null)` (Default-Werte statt
    Body-Änderung, um Binding-basiertes Verhalten von `DB2Builder::hasTable()` zu erhalten).
  - `Schema\Blueprint::indexCommand($type, $columns, $index, $algorithm = null, $operatorClass = null)`
    — neuer optionaler Parameter `$operatorClass` (Postgres-Operatorklassen). Ohne ihn: Fatal Error
    beim Klassenladen (nicht nur beim Aufruf!). **Das ist der teuerste Fehlermodus von V2** — er
    tritt schon beim `require`/Autoload der Klasse auf, nicht erst bei Nutzung.
  - **Wichtige PHP-Erkenntnis:** Konstruktoren sind von PHPs Signaturkompatibilitätsprüfung
    ausgenommen (`__construct` darf beliebig enger typisieren als der Elternkonstruktor, empirisch
    verifiziert). Normale Methoden dürfen dagegen NICHT weniger/strengere Parameter haben als der
    Elternteil (führt zu `Fatal error: Declaration ... must be compatible`), dürfen aber
    zusätzliche **optionale** Parameter (mit Default) ergänzen und den Rückgabetyp verengen
    (Kovarianz), auch wenn der Elternteil gar keinen Rückgabetyp deklariert.
  - **Vorgehen bei zukünftigen Laravel-Upgrades:** NICHT auf Doku/Gedächtnis verlassen — immer
    per `grep`/`read` gegen `vendor/laravel/framework/src/Illuminate/Database/**` die reale
    Signatur verifizieren, dann zusätzlich mit einem Mini-Skript (`new PDO`-freie Instanziierung
    aller Schema-Klassen + `ReflectionMethod`-Aufrufe) auf `Fatal error: Declaration ... must be
    compatible` prüfen. Das deckt Fälle ab, die nur beim Klassenladen (nicht bei jedem Testlauf)
    auffallen, wie `indexCommand()`.
- **V3 — Verbindungen sind jetzt lazy.** `DB2ServiceProvider` bindet `db.connector.db2` im
  Container (nötig, weil `ConnectionFactory::createConnector()` sonst mit
  `"Unsupported driver [db2]"` abbricht, sobald die lazy-PDO-Closure aufgerufen wird) und gibt
  die von Laravel übergebene Closure unverändert an `DB2Connection` weiter, statt
  `$connector->connect($config)` sofort aufzurufen. `DB2Connection::__construct` akzeptiert
  `PDO|\Closure $pdo` (safe dank Konstruktor-Ausnahme, siehe oben).
- **V6 — behoben.** `DB2Processor::processInsertGetId` nutzt jetzt `$query->getGrammar()->columnize()`
  statt `new DB2QueryGrammar()` (Table-Prefix-Verlust behoben).
- **V7 — behoben.** `DB2Connection::setCurrentSchema('')` sendet jetzt `SET SCHEMA DEFAULT` als
  literales SQL-Keyword statt `SET SCHEMA ?` mit Binding `"DEFAULT"`.

## Kritisch (🔴) — weiterhin offen

- **V1 (strukturell erledigt)** — nur noch Laravel 12 deklariert, kein Multi-Version-Support mehr
  vorzutäuschen.
- **V4 — behoben.** `compileRenameColumn()` (ALTER TABLE ... RENAME COLUMN ... TO ...) und
  `compileChange()` (mehrere ALTER-COLUMN-Klauseln: SET DATA TYPE, SET/DROP NOT NULL, SET DEFAULT)
  in `DB2SchemaGrammar` ergänzt. `compileChange()` diffed bewusst nicht gegen den alten
  Spaltenzustand (keine Introspection, siehe V5) und wirft für autoIncrement/generated/renameTo
  eine sprechende `RuntimeException` statt falsches SQL zu erzeugen. Getestet in
  `tests/Unit/DB2SchemaGrammarTest.php`.

## Mittel (🟠)

- **V5** — Schema-Introspection fehlt (`compileTables/Views/Columns/Indexes/ForeignKeys`, `dropAllTables()`) → `Schema::getColumns()`, `db:show`, `db:table`, `migrate:fresh` funktionieren nicht.
- **V8** — `phpunit.xml.dist` ist PHPUnit-9-only (`backupStaticAttributes`, `convertErrorsToExceptions`, `verbose`) → bricht auf PHPUnit 10+.
- **V10** — Kein CI, `pint`/`phpstan` fehlen in `require-dev`, `phpstan.neon`+`testbench.yaml` gitignored, `minimum-stability: dev`, README bewirbt alten Paketnamen.

## Kritisch (🔴) — behoben (weitere)

- **V9 — behoben.** Zentrales Escaping einfacher Anführungszeichen in
  `DB2SchemaGrammar::compileExecuteCommand()` (`str_replace("'", "''", ...)`). Aufrufer dürfen
  NICHT mehr selbst vor-escapen — `compileAddReplyListEntry()` baute vorher `RPY(''I'')` von Hand,
  das wurde zu `RPY('I')` korrigiert (sonst doppeltes Escaping → `''''`). `README.md` bewarb noch
  `bwicompanies/db2-driver` (V10-Symptom, README-Teil separat gefixt: Paketname + Banner-URL auf
  `beryildiz` korrigiert, Laravel-12-only-Hinweis in Requirements ergänzt).

## Duplikationen

- `explode('.', $table)` + `str_replace($schema.'_', '', $index)` **11× dupliziert** in 4 Varianten (DB2SchemaGrammar 8×, DB2Builder 3×) → `Support/QualifiedTableName` VO.
- `wrapValue()` identisch in `DB2QueryGrammar` + `DB2SchemaGrammar`. Wichtig: Methode heißt `wrapValue`, **wrapped aber absichtlich nicht** (IBM i: `"…"` erzwingt Case-Sensitivität und bricht System-Naming) — braucht Kommentar, sonst wird sie „repariert".
- `SET SCHEMA` an 2 Orten (`DB2Connector::connect()`, `DB2Connection::setCurrentSchema()`).
- `QCMDEXC` an 2 Orten (`DB2Connection::executeCommand()`, `DB2SchemaGrammar::compileExecuteCommand()`).
