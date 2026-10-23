# Changelog

## Unreleased

- Provide a PHP 8.2+ PSR-4 core under `Lfcheng\RelationalMigrator`, with no runtime Composer packages.
- Declare entities, deterministic transforms, references and stable topological order; reject cyclic or unknown dependencies before I/O.
- Preserve typed simple/composite source keys, immutable scoped ID maps and output fingerprints; offer explicit missing-reference policies and partial failure reports.
- Include memory adapters, actual SQLite/PDO adapters, synthetic examples and standalone Composer consumer verification.
- Keep target rows and idempotency receipts in one SQLite main-schema transaction, verify postconditions before commit, and protect map read-back checks with savepoints.
- Reject invalid connection/error/journal settings, hidden rowid assignment and mismatched replays. Qualify main-schema tables so TEMP names cannot redirect persistence.
- Test trigger ignore/revert/corruption, real post-commit process exit, concurrent source-key contention, recovery and typed composite persistence.
- License original code under MIT while preserving third-party license boundaries.

No schema migration, automatic target deletion, business-data dry run, update synchronization, distributed exactly-once or general rollback API is provided.
