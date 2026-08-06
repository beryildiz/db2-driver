# Architektur-Befunde — db2-driver

Stand: Option-B-Umsetzung abgeschlossen (Laravel-12-Migration + Bugfixes, ohne Introspection).
v1.0.0 auf Packagist veröffentlicht und in einem echten Verbraucherprojekt gegen eine
produktive IBM-i-Instanz verifiziert (siehe „Produktiv-Verifikation" unten).

## Status: alle Befunde geschlossen

`vendor/bin/pint --test` ✅ · `vendor/bin/phpstan analyse` ✅ 0 Fehler · `vendor/bin/pest` ✅ 102 Tests

**Kein offener Blocker, kein unverifiziertes Katalog-SQL im Paket.**

## Produktiv-Verifikation gegen echtes IBM i (nicht nur gemockt)

Alle Unit-/Pest-Tests im Paket laufen gegen gemockte `Connection`/`Grammar`-Instanzen (kein
CI-Zugang zu echter IBM-i-Hardware). Ein Verbraucherprojekt hat `beryildiz/db2-driver` v1.0.0
per `composer require` gezogen und gegen eine echte IBM-i-Instanz produktiv getestet:

- **Read via Eloquent**: `Modelp::query()->select([...])->where(...)->where(...)->get()` sowie
  `Grrastp::query()->where(...)->where(...)->orderBy(...)->orderBy(...)->get()` — mehrere
  `where()`/`orderBy()`-Ketten, explizite Spaltenlisten mit Groß-/Kleinschreibung wie in DB2
  üblich (`MOSAIS`, `FORM8`, `GRTXT`), führende Nullen/Buchstaben in String-Werten (`030`,
  `30K`, `30L`) bleiben unverändert — kein Cast-/Truncation-Problem.
- **Vollständiger CRUD-Roundtrip** (`Berkan`-Model, Tabelle `LEIOBJ.BERKAN`, PHPUnit-Integrationstest
  mit `setUp()`/`tearDown()`-Cleanup gegen eine reservierte Test-ID):
  - `create()` → bestätigt `DB2Processor::processInsertGetId()` (liest den Key case-insensitiv
    zurück, auch wenn `ID` explizit statt über eine Identity-Spalte vergeben wird — die
    `SELECT … FROM NEW TABLE`-Umhüllung funktioniert auch dafür).
  - `find()` → Primary-Key-Lookup über schema-qualifizierte Tabelle (`LEIOBJ.BERKAN`) liefert
    korrekt.
  - `update()` → `compileUpdate()` mit mehreren geänderten Spalten inkl. reinem Datumsfeld
    (`Y-m-d`, kein Timestamp-Cast-Problem).
  - `delete()` → `compileDelete()` mit WHERE-Bedingung, anschließendes `find()` liefert `null`.

**Damit verifiziert:** `DB2Connection`/`DB2QueryGrammar`/`DB2Processor` funktionieren nicht nur
API-kompatibel unter Laravel 12 (statisch/gemockt geprüft), sondern erzeugen auch tatsächlich
korrektes, ausführbares SQL gegen eine echte DB2-for-i-Instanz — für Lese- und Schreibpfade via
Eloquent. Nicht durch dieses Beispiel abgedeckt: `upsert()`/`insertOrIgnore()` (bekannt
unsupported), Relations, Soft Deletes, Pagination via `offset()`/`limit()` im Produktivbetrieb,
Schema-Introspection.

## Referenz-Fork (wichtig für künftige Upgrades)

`anchan42/db2-driver` gilt in der Community als „der Laravel-12-Fix", ist es aber nur für den
**Query-Pfad**. Der Fork ändert 3 Dateien (`composer.json`, `DB2Connection`, `DB2Processor`),
`src/Schema/*` bleibt unangetastet. Damit fatalt dort **jeder `Schema::`-Aufruf und jedes
`php artisan migrate`** unter L12. Nicht als Vollständigkeits-Referenz verwenden.

## Verifizierte L12-Signaturbrüche im Schema-Layer

Alle in unserem Fork behoben, in `anchan42` offen. Gegen `laravel/framework 12.64.0` verifiziert:

| Baseline-Code | L12-Parent | Fehlermodus |
|---|---|---|
| `DB2Blueprint::toSql(Connection, Grammar)` | `toSql()` — 0 Parameter | Fatal error beim Klassenladen |
| `DB2Blueprint::indexCommand($t,$c,$i,$algorithm=null)` | `indexCommand(…, $operatorClass=null)` | Fatal error beim Klassenladen |
| `DB2SchemaGrammar::compileTableExists()` — 0 Parameter | `compileTableExists($schema,$table)` | Fatal error beim Klassenladen |
| `DB2Builder::build()` → `$blueprint->build($conn,$grammar)` | `build()` parameterlos | `ArgumentCountError` |
| `DB2Builder::createBlueprint()` → `new DB2Blueprint($table,$cb)` | `Blueprint::__construct(Connection,$table,?Closure)` | `ArgumentCountError` |
| `compileCreate/Label/AddReplyListEntry(…, Connection)` | `Blueprint::toSql()` ruft mit 2 Args | `ArgumentCountError` |
| `compileAdd()` iteriert alle Blueprint-Spalten | `addImpliedCommands()` erzeugt 1 add-Command pro Spalte (`Blueprint.php:193-205`) | N×N Statements, „column already exists" |
| `$this->tablePrefix` in `compileAnsiOffset` | Property entfernt; `Grammar::$connection` bleibt (kein Fehler) | Prefix verschwand still — Fix: `wrapTable()` statt manueller Konkatenation |

**Lehre:** Konstruktoren sind von PHPs Signaturprüfung ausgenommen, normale Methoden nicht.
Fatal errors treten beim **Klassenladen** auf, nicht erst bei Nutzung — deshalb bei jedem
Laravel-Upgrade die realen Signaturen in `vendor/laravel/framework/src/Illuminate/Database/**`
prüfen, nicht Doku oder Gedächtnis.

**Kein Bug:** `Illuminate\Database\Grammar::$connection` (protected) existiert weiterhin und wird
im Konstruktor gesetzt; `Query\Grammars\Grammar` redeklariert es nicht. Meldet der Editor
„Undefined property '$connection'" auf einer Grammar-Subklasse, ist das ein veralteter
Intelephense-Index (Workspace neu indexieren), kein echter Fehler — PHPStan/Larastan bestätigt
das. `$query->getConnection()->getTablePrefix()` (Vorbild `SQLiteGrammar`) ist trotzdem **keine**
Alternative für uns: `ConnectionInterface` deklariert `getTablePrefix()` nicht, das würde Larastan
brechen. `Grammar::getTablePrefix()` ist zudem `@deprecated`. Richtige Lösung: `wrapTable()`.

## Bewusst nicht implementiert (Basisklasse meldet es sauber)

| Feature | Verhalten | Grund |
|---|---|---|
| `compileTables/Views/Types/Columns/Indexes/ForeignKeys/Schemas` | `RuntimeException` aus Basis-Grammar | Katalog-SQL ohne IBM-i-Zugang nicht verifizierbar |
| `dropAllTables()`/`dropAllViews()`/`dropAllTypes()` | `LogicException` aus Basis-Builder | Kein globaler FK-Schalter, kein `DROP TABLE CASCADE` auf DB2 for i → Teilimplementierung wäre gefährlicher als ein klarer Fehler. **`migrate:fresh`/`db:wipe` damit nicht verfügbar** |
| `getSchemaState()` | `BadMethodCallException` | `schema:dump` nicht unterstützt |
| `escapeString()`/`escapeBinary()` | `PDO::quote()` gibt bei PDO_ODBC `false` | `DB::pretend()`/Query-Log-Rohsql unbrauchbar (W7, eigener Scope) |
| `compileRandom()` | Basis liefert `RANDOM()`, DB2 for i braucht `RAND()` | `inRandomOrder()` defekt (W9, eigener Scope) |
| 9 Spaltentypen (`json`, `jsonb`, `ulid`, `tinyInteger`, `mediumInteger`, `tinyText`, `year`, `ipAddress`, `macAddress`) | `BadMethodCallException` über `Macroable::__call` | Wie im Original-Paket; kein sauberes DB2-Mapping |

Alles davon ist im README unter „Limitations" dokumentiert und durch Tests abgesichert.

## Falls Introspection später gebaut wird

Der frühere Introspection-Versuch wurde entfernt, weil er 5 Blocker enthielt. Wer ihn neu
angeht, muss diese Punkte adressieren — sie sind alle **vor** dem ersten Release gegen ein
echtes IBM i zu verifizieren:

| ID | Problem |
|---|---|
| B1 | `syskeycst kc`/`kp` nur über Constraint-Name gejoint → kartesisches Produkt; Positions-Join nötig |
| B2 | Positionsspalte in `QSYS2.SYSKEYCST` heißt nach IBM-Doku `COLUMN_POSITION`, nicht `ORDINAL_POSITION` (`SYSKEYS` nutzt `ORDINAL_POSITION`) |
| B3 | `dropAllTables()` ohne Schema-Guard löscht bei fehlendem `config.schema` alle Tabellen aller Bibliotheken |
| B4 | `DROP TABLE … CASCADE` existiert auf DB2 for i nicht (nur `RESTRICT`) |
| B5 | Basis-`getColumns()` ruft `parseSchemaAndTable()` **ohne** `withDefaultSchema` → Schema ist `null`. Tabellenbezogene Queries müssen dann auf `CURRENT SCHEMA` defaulten (wie Postgres), nicht auf „alle Nicht-System-Schemas" |
| W3 | `SYSCOLUMNS.IS_GENERATED` unverifiziert — `COLUMN_EXPRESSION` allein genügt zur Ableitung, damit entfällt das Risiko |
| W4 | `SYSINDEXES.IS_UNIQUE` existiert und sollte gemappt werden, statt `unique` hart auf 0 zu setzen |
| W5 | System-Schema-Ausschlussliste war lückenhaft (`QGPL`, `QIBM`, `SYSIBMTS`, `QSYS38`, `QPFRDATA`, `SYSPARTITIONINDEXES` fehlten) |

Empfohlenes Vorgehen: `compileSchemaWhereClause` in **zwei** Methoden trennen (Listen-Scope mit
System-Ausschluss vs. Tabellen-Scope mit `CURRENT SCHEMA`), und vor dem Merge einen
Integrationstest `select <spalten> from qsys2.<view> where 1 = 0` pro Katalogview laufen
lassen — null Zeilen, keine Datenabhängigkeit, aber SQL0206 bei jedem falschen Spaltennamen.

## Korrigierte Fehleinschätzungen (nicht wiederholen)

- **`drop table if exists` ist Baseline-Verhalten**, kein Bug. Ein früherer Test forderte das
  Gegenteil und war die Neuerfindung. IBM i ≥ 7.2 unterstützt es; im README dokumentiert.
- **Introspection war keine L12-Regression.** Die Methoden gab es im Paket nie; L12 hat daran
  nichts gebrochen. Die Basisklasse fängt ihre Abwesenheit mit `RuntimeException` ab.
  Der einzige introspection-nahe L12-Bruch ist `compileTableExists()` (Signatur).

## Bekannte technische Schuld (bewusst akzeptiert)

- `compileTableExists()` ignoriert seine `$schema`/`$table`-Parameter und liefert
  `?`-Platzhalter-SQL; `DB2Builder::hasTable()` übergibt sie als Bindings. Abweichend vom
  L12-Vertrag (literales SQL + `scalar()`), aber jahrelang produktiv erprobt — ohne IBM-i-Zugang
  nicht anzutasten. Gleiches gilt für `compileColumnExists()` (kein L12-API mehr).
- `hasTable`/`getColumnListing` nutzen `information_schema.*`. Konsolidierung auf `qsys2.*`
  erst gemeinsam mit einer verifizierten Introspection.
- `spatie/laravel-package-tools` bleibt Runtime-Dependency, obwohl `DB2DriverServiceProvider`
  nur noch `$package->name()` aufruft. Entfernen wäre ein BC-Bruch (Nutzer könnten den Provider
  in `bootstrap/providers.php` listen).
- `DB2SchemaGrammar` ist 887 Zeilen; Zerlegung in Traits siehe `mem:architecture/decisions`.
- Duplikation: `explode('.', $table)` + Schema-Stripping mehrfach in `DB2SchemaGrammar`;
  `wrapValue()` identisch in beiden Grammars (quotet absichtlich **nicht** — IBM i erzwingt mit
  `"…"` Case-Sensitivität und bricht System-Naming).
