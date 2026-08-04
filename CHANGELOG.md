# Changelog

All notable changes to `beryildiz/db2-driver` will be documented in this file.

## [Unreleased]

### Changed
- **BREAKING:** Package now exclusively supports Laravel 12 (`illuminate/* ^12.0`, PHP `^8.2`). Dropped support for Laravel 9/10/11.
- Fixed `Blueprint`/`Grammar` method signatures to match Laravel 12's API (removed obsolete `Connection` parameters, added `$operatorClass` to `indexCommand()`).
- Connections are now resolved lazily (PDO is no longer opened eagerly on connection resolution).
- `setCurrentSchema('')` now sends `SET SCHEMA DEFAULT` as a literal SQL keyword instead of a bound parameter.
- `DB2Processor::processInsertGetId()` now uses the query's actual grammar instead of instantiating a fresh, unconfigured one.

### Added
- `compileRenameColumn()` and `compileChange()` support for Schema Blueprint operations.

### Fixed
- Escaping of single quotes in `QSYS2.QCMDEXC` system command calls (removed duplicate escaping in reply-list commands).

### Renamed
- Namespace changed from `BWICompanies\DB2Driver` to `Beryildiz\DB2Driver`. Package renamed from `bwicompanies/db2-driver` to `beryildiz/db2-driver`.
