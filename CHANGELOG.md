# Changelog

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
