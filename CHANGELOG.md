# Changelog

## Unreleased — namespace alignment before public release

- Standardize runtime and development PSR-4 namespaces on `Lfcheng\RelationalMigrator\`.
- Composer package name stays `lfcheng92/relational-migrator`; repository, behavior, dependencies and license are unchanged.
- Earlier private-candidate consumers must update imports/FQCNs and regenerate Composer autoload files; no compatibility aliases are provided.

## Unreleased — SQLite rowid alias guard

- Reject hidden rowid/_rowid_/oid assignment, including case variants, before target mutations.
- Use table_xinfo metadata to preserve real declared non-primary-key columns with the same names, including WITHOUT ROWID tables.
- Verify failed alias mappings leave target rows, receipts and ID maps unchanged.

## Unreleased — journal contract follow-up

- Recheck supported SQLite journal modes before helper initialization and every ID-map put; reused connections switched to OFF fail before mutation.
- Verify target/receipt rollback and retry in DELETE, TRUNCATE, PERSIST, MEMORY and WAL modes using real temporary SQLite files.
- Separate in-process exception rollback from process-crash and power-loss durability in both READMEs and security guidance.

## Unreleased — local first version

- Added PHP 8.2+ PSR-4 core with no runtime Composer dependencies.
- Added validated entity/field/reference declarations and stable topological ordering; cyclic graphs fail before I/O.
- Added typed simple/composite source keys, deterministic output fingerprints, immutable scoped ID maps and explicit missing-reference policies.
- Added fail-fast execution reports with partial progress and conservative ambiguous-write indication.
- Added in-memory test adapters and actual PDO SQLite source, target and persistent map adapters.
- Added atomic SQLite target-row/idempotency-receipt transactions to recover from the target-commit/map-save gap.
- Added synthetic examples, unit/integration/failure-recovery tests, PHPStan max, syntax checks, offline mirrored consumer verification and non-publishing CI.
- Made float fingerprints independent of serialize_precision, bound SQLite floats with an explicit round-trip decimal, and rejected journal_mode=OFF for write adapters.
- Fixed statement cleanup after SQLite lock failures, verified by retrying on the same connection.
- Recorded provisional private license, source boundaries and separate private-upload/public-release gates.

No remote repository, public release, schema migration, automatic target deletion, update synchronization, general rollback or business-data dry run is included.
