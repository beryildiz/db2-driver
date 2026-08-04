# Architektur-Entscheidungen — db2-driver

Ziel-Architektur für ein Laravel-**Driver-Package** (erweitert Illuminate-Basisklassen).
App-Layering (Actions/Controllers/Resources) ist hier NICHT anwendbar.

## Leitregel für Abstraktionen

Ein polymorpher Seam (Interface) nur, wenn eine **zweite Implementierung heute existiert
oder konkret geplant** ist. Sonst: benannter Kollaborator, Trait oder Value Object.

**Kerngrenze:** Abstrahiere Variabilität, die *wir* kontrollieren (Verbindungsweg,
SQL-Dialektvariante). Abstrahiere **nie** Variabilität, die das Framework in Signaturen
unserer Elternklassen kontrolliert — dort ist Paket-Versionierung die einzige Antwort.

## Adapter/Strategy — Urteile

| Stelle | Urteil | Form | Begründung |
|---|---|---|---|
| DSN / Verbindungsweg | ✅ machen | Interface `Db2Transport` (2 Impls) | Named-DSN via unixODBC häufigste Anfrage; `getDsn()` bereits testbar |
| `offsetCompatibilityMode` | ✅ machen | **Vererbung**, kein Interface | 2 disjunkte Algorithmen über 6 Methoden; Legacy-Pfad braucht `protected` Grammar-Interna (`compileComponents`, `concatenate`, `$tablePrefix`) → Composition kämpft gegen Framework |
| Laravel-Versionsunterschiede | ❌ NICHT machen | Matrix beschneiden | PHP prüft Signaturkompatibilität zur **Klassendeklarations-Zeit**. Traits/`method_exists`/`version_compare` helfen nur bei *Rümpfen*, nie bei *Signaturen*. Eine Anti-Corruption-Layer gegen eine Elternklasse ist unmöglich. |
| QCMDEXC | ✅ machen | Value Object, kein Interface | Genau ein Weg (`QSYS2.QCMDEXC`), wird nie einen zweiten geben. Nutzen = Deduplizierung + **eine** Escaping-Stelle |
| Schema-Namen-Parsing | ✅ machen | VO `QualifiedTableName` | 11× dupliziert in 4 Varianten = Bug-Farm |

`Db2Transport` bewusst als *Transport* (nicht `DsnBuilder`): `dsn()` + `afterConnect()` +
`defaultOptions()`. `ibm_db2`/PDO_IBM ist kein anderer DSN, sondern anderer PDO-Treiber mit
anderen Attributen/Quoting — ein `DsnBuilder`-Interface wäre eine Lüge im Typsystem.

## Multi-Version-Support

**Gewählt: ein Paket-Major pro Laravel-Major.** Composer löst das für den Nutzer automatisch
auf; Drop-in-Zusage bleibt erhalten. Verworfen: Traits pro Version, `class_alias`,
conditional class loading (lösen Signatur-Drift nicht bzw. brechen PHPStan/IDE).

Capability-Detection nur für *additive, optionale* Features, gebündelt in **einer** Klasse
`Support/LaravelFeatures` (≤5 statische Methoden) — explizit **keine** Versions-Abstraktionsschicht.

**Vorgehen: erst messen, dann schneiden.** Nur Laravel-Versionen deklarieren, für die ein
grüner CI-Job existiert. Drei parallele Branches bei einem Maintainer sind ein Versprechen,
das nicht gehalten wird — ein nicht gehaltenes Kompatibilitätsversprechen ist schlimmer als
ein enges.

## DB2SchemaGrammar-Zerlegung (823 Zeilen)

**Traits, nicht Composition.** Grund: Illuminate dispatcht Schema-Grammar über
**Namenskonventionen** (`compile{Command}`, `type{Type}`, `modify{Modifier}`). Composition
erzwingt Nachbau des Dispatch oder `__call()` → verliert IDE-Navigation und PHPStan-Auflösung.
Traits erhalten den Dispatch unverändert, kosten zur Laufzeit nichts.

Schnitt folgt den Fragen, die Contributor tatsächlich stellen:
```
Schema/Concerns/
  CompilesTableCommands.php         # create/label/add/drop/rename/dropColumn/renameColumn/change
  CompilesIndexCommands.php         # primary/unique/index/foreign + drop*
  CompilesIntrospectionQueries.php  # tableExists/columnExists/tables/views/columns/indexes
  CompilesSystemCommands.php        # addReplyListEntry/removeReplyListEntry/changeJob
  RendersColumnTypes.php            # 19× type*()  — häufigster Erst-PR
  RendersColumnModifiers.php        # 8× modify*() + getDefaultValue()
```
`DB2SchemaGrammar` bleibt ~90 Zeilen Einstiegspunkt.

## Typisierte Konfiguration

`Config/DB2Config` als **internes DTO**, nicht als Ersatz der öffentlichen API. Vier Regeln:

1. `Connection::$config` bleibt das Array (Laravel-Core/Drittpakete lesen `getConfig('driver')`).
   DTO ist zusätzlicher, memoisierter Accessor `DB2Connection::db2Config()`.
2. Unbekannte Keys **ignorieren**, nicht ablehnen — sonst bricht jeder Nutzer mit
   `read`/`write`/`sticky`/`name`/`prefix_indexes`. Nur Pflicht-Keys + Werttypen validieren.
3. `fromArray()` ist die **einzige** Stelle für Defaults (heute 4 Orte verstreut).
4. **Kein** publiziertes `config/db2.php` — würde Drop-in-Zusage brechen.

Motivation: Tippfehler (`offsetCompatibilityMode` statt `offset_compatibility_mode`) scheitern
heute **stumm** mit falschem Paging-SQL = teuerster Fehlermodus des Pakets.

## Service Provider

Konsolidieren auf **einen** (`DB2ServiceProvider`), registriert zusätzlich `db.connector.db2`
(nötig für lazy PDO + korrektes `DB::purge`/`reconnect`). `DB2DriverServiceProvider` macht nur
`publishesServiceProvider()` (Spatie-Skeleton-Rest) und zieht `spatie/laravel-package-tools`
als Runtime-Dependency → raus aus `require`. Alter Provider bleibt einen Major als leerer
`@deprecated` Shim (Nutzer könnten ihn in `bootstrap/providers.php` gelistet haben).

## Teststrategie (3 Stufen)

| Stufe | Was | IBM i? |
|---|---|---|
| 1 | **SQL-Snapshots** (Golden Files, `spatie/pest-plugin-snapshots`) | ❌ |
| 2 | Connection-Contract gegen `RecordingPdo`-Fake (SET-SCHEMA-Sequenz, Reply-List-**Reihenfolge**, insertGetId) | ❌ |
| 3 | Integration, opt-in via `env('DB2_TEST_HOST')`, PHPUnit-Gruppe `integration` | ✅ |

Stufe 1 ist der eigentliche Contributor-Enabler und ersetzt die heutigen schwachen
`toContain()`-Asserts. Für Stufe 3 in Doku nennen: **PUB400.com** (kostenlose Community-IBM i)
— ohne diesen Hinweis kann faktisch niemand außer dem Maintainer beitragen.

## PHPStan

**Level 5, keine Baseline.** Zwei Config-Parameter in `phpstan.neon` ersetzen die alte Baseline:
- `universalObjectCratesClasses: [Illuminate\Support\Fluent]` — Fluent ist per Design ein
  Property-Bag; PHPStan soll keine `property.notFound` auf `Fluent`/`ColumnDefinition` melden.
- `treatPhpDocTypesAsCertain: false` — Laravel-PHPDoc ist nachweislich zu lax (nullable
  Properties ohne `?`); native Typen bleiben voll geprüft.
Echte method.notFound-Fehler in `DB2Builder`/`DB2SchemaGrammar` sind durch Narrowing-Accessoren
mit `instanceof`-Guard behoben. Level-Steigerung (6+) ist jetzt möglich, aber erst nach V5
(Introspection) sinnvoll — dann gibt es echten Coverage-Gewinn.

## CI

`prefer-lowest` **und** `prefer-stable` pro Laravel-Version — einzige Absicherung gegen zu
weite `^`-Constraints, also gegen genau die Fehlerklasse aus V1/V2.

## ARCHITECTURE.md — Pflichtinhalte

Der #1-Onboarding-Blocker ist nicht die Ordnerstruktur, sondern *„welche Illuminate-Methode
überschreibe ich hier und warum?"*. Genau drei Dinge:
1. Request-Fluss-Diagramm (`config/database.php` → Resolver → Connector → Transport → PDO → Connection → Grammar/Builder → Processor)
2. **Override-Tabelle**: unsere Klasse | Illuminate-Parent | überschriebene Methoden | Grund | Risiko bei Laravel-Upgrade → ist gleichzeitig Upgrade-Checkliste und macht V2 künftig unmöglich
3. Kompatibilitäts-Tabelle Paket ↔ Laravel ↔ PHP

ADRs nur für die 6 Kernentscheidungen (MADR, ~15 Zeilen). ADR-Pflicht pro PR wäre
Contribution-Bremse.

Befunde/Bugs: `mem:architecture/findings`
