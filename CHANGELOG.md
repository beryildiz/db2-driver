# Changelog

All notable changes to `beryildiz/db2-driver` will be documented in this file.

## [Unreleased]

### Breaking

- Package now exclusively supports Laravel 12 (`illuminate/* ^12.0`, PHP `^8.2`). Dropped support for Laravel 9/10/11.
- Namespace changed from `BWICompanies\DB2Driver` to `Beryildiz\DB2Driver`. Package renamed from `bwicompanies/db2-driver` to `beryildiz/db2-driver`.
- `$table->index($columns, 'name')` now honours the supplied index name. Previously the name was discarded and replaced by a generated one, so index names created by older versions may differ.
- `DB2DriverServiceProvider` no longer registers the `db2-driver-provider` publish tag. It pointed at `resources/stubs/DB2ServiceProvider.php.stub`, which has never existed in this package, so publishing was a no-op.

### Laravel 12 compatibility

Method signatures across the schema layer were incompatible with Laravel 12 and caused fatal
errors as soon as any `Schema::` call or migration ran:

- `DB2Blueprint::toSql()` is now parameterless — the parent declares no parameters, so the previous `toSql(Connection, Grammar)` was a fatal declaration mismatch.
- `DB2Blueprint::indexCommand()` gained the parent's `$operatorClass` parameter (fatal declaration mismatch without it).
- `DB2SchemaGrammar::compileTableExists()` now accepts the `$schema`/`$table` parameters the parent declares (fatal declaration mismatch without them).
- `DB2Builder::createBlueprint()` passes the connection as the blueprint's first constructor argument and calls the blueprint resolver with `($connection, $table, $callback)`.
- `DB2Builder::build()` calls the now-parameterless `Blueprint::build()`.
- Removed the obsolete `Connection` parameter from `compileCreate()`, `compileLabel()` and `compileAddReplyListEntry()`; the grammar reaches its connection via `$this->connection`.
- `compileAdd()` now compiles the single column carried by its command. Laravel 11+ emits one `add` command per column, so iterating all blueprint columns produced N×N statements.
- Query and schema grammars receive the connection in their constructor; `Connection::withTablePrefix()` and `Grammar::setConnection()` no longer exist.
- `compileAnsiOffset()` now builds the row-number column via `wrapTable()` instead of manually concatenating the connection's table prefix — `Grammar::$tablePrefix` was removed and `Grammar::getTablePrefix()` is deprecated.

### Fixed

- `compileDropForeign()` emitted MySQL syntax (`drop foreign key`); now `drop constraint`.
- `compileDropUnique()` and `compileDropIndex()` emitted `alter table ... drop index`, which DB2 for i does not support; now `drop constraint` and `drop index` respectively.
- `getDefaultValue()` interpolated `false` into an empty `DEFAULT` clause, did not escape single quotes in string defaults, and crashed on enum defaults.
- `typeEnum()` emitted `enum(...)`, a type DB2 for i does not have; now `varchar` with a `CHECK` constraint.
- `typeFloat()`/`typeDouble()` read `$total`/`$places`, which Laravel 11+ no longer sets, producing `decimal(, )`.
- `typeDate()`, `typeTime()` and `typeTimestamp()` appended an implicit `DEFAULT` even when an explicit default was given, yielding two `DEFAULT` clauses.
- `typeBoolean()` did not quote the column name in its `CHECK` constraint.
- `modifyIncrement()` built constraint names from the schema-qualified table name, producing an invalid name containing a dot.
- Added `compileRenameColumn()` and `compileChange()` — without them `renameColumn()` and `change()` were inoperative after Doctrine DBAL was dropped in Laravel 11.
- Single quotes in `QSYS2.QCMDEXC` calls are now escaped in exactly one place; the reply-list command no longer double-escapes.
- `DB2Builder::getColumnListing()` resolved the schema from `getDatabaseName()` instead of the configured schema, and applied the table prefix before splitting off the schema name (`pfx_MYLIB.tab`).
- `DB2Builder::build()` restores the connection schema in a `finally` block and only when it actually switched; a failed migration previously left the schema switched for all subsequent queries.
- `DB2Connector::connect()` sent `SET SCHEMA ?` as a bound parameter and did not normalise case; the schema name is now validated and inlined.
- `setCurrentSchema('')` sends `SET SCHEMA DEFAULT` as a literal keyword instead of binding the string `"DEFAULT"`.
- `DB2Processor::processInsertGetId()` uses the query's own grammar instead of a fresh, unconfigured one (which lost the table prefix), reads the generated key case-insensitively, and throws a descriptive exception when the column is absent instead of returning `null`.
- `compileAnsiOffset()` put the table prefix before the schema name for schema-qualified tables (`pfx_MYLIB.employees` instead of `MYLIB.pfx_employees`); `wrapTable()` now handles prefixing, aliases and `Expression` values correctly.
- `DB2Connection::__construct()` accepts a `Closure`, so connections resolve lazily and no longer open an ODBC socket on every `DB::connection('db2')`.
- `DB2Connection::__construct()` no longer passes `null` to `strtoupper()` when the `schema` key is absent.
- `DB2Blueprint::$systemName` is a declared property (was a dynamic property, deprecated as of PHP 8.2).

### Notes

- Schema introspection (`Schema::getTables()`, `getColumns()`, `db:show`, `db:table`) and `dropAllTables()`/`dropAllViews()` remain unimplemented, as in previous releases. Laravel's base classes report this with an explicit exception. See the Limitations section in the README.
