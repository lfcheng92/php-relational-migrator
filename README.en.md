# Relational Migrator

[中文](README.md)

A framework-independent PHP library for migrating relational **data**, transforming fields, persisting old-to-new ID mappings, and resolving foreign keys in dependency order. It does not version or migrate database schemas.

This is a local first version. No remote repository or Packagist release exists, and no open-source license has been granted. The intended package name is `lfcheng92/relational-migrator`. Read [PROVENANCE.md](PROVENANCE.md), [LICENSE](LICENSE), and [THIRD_PARTY.md](THIRD_PARTY.md). Reimplementation alone does not settle ownership or licensing rights.

## Requirements and local installation

The core requires PHP `^8.2` and has no runtime Composer dependencies. SQLite adapters need `ext-pdo`, `ext-pdo_sqlite`, and SQLite 3.35+. Distribution verification additionally requires `ext-zip`, `proc_open`, and Composer 2. PHPUnit and PHPStan are development-only dependencies, locked in `composer.lock`.

Use a Composer path repository in an adjacent consumer project; this package is not available on Packagist:

```json
{
  "repositories": [{
    "type": "path",
    "url": "../php-relational-migrator",
    "options": {
      "symlink": false,
      "versions": {"lfcheng92/relational-migrator": "dev-main"}
    }
  }],
  "require": {"lfcheng92/relational-migrator": "dev-main"}
}
```

Run `composer install --no-plugins --no-scripts` and require `vendor/autoload.php`. Disabling symlinks verifies a copied installation.

## Example

```php
<?php
declare(strict_types=1);
require 'vendor/autoload.php';
use Lfcheng92\RelationalMigrator\{Entity, Field, MigrationPlan, Migrator, Reference};
use Lfcheng92\RelationalMigrator\Adapter\Memory\{
    ArraySourceReader, InMemoryIdMapStore, InMemoryTargetWriter
};
$plan = new MigrationPlan('snapshot-demo-v1',
    new Entity('posts', ['region', 'old_id'], ['title' => Field::from('title')],
        ['author_id' => new Reference('authors', ['old_author_id'])]),
    new Entity('authors', ['old_id'], ['name' => Field::from('name')]),
);
$source = new ArraySourceReader([
    'authors' => [['old_id' => 40, 'name' => 'Ada']],
    'posts' => [['region' => 'demo', 'old_id' => 91,
        'old_author_id' => 40, 'title' => 'Synthetic data']],
]);
$writer = new InMemoryTargetWriter();
$maps = new InMemoryIdMapStore();
$migrator = new Migrator($source, $writer, $maps);
$first = $migrator->run($plan);  // authors -> posts; inserted=2
$repeat = $migrator->run($plan); // alreadyMapped=2
```

Memory adapters are for tests and short-lived examples; they do not persist across processes. Executable examples: [memory](examples/memory.php) and [SQLite](examples/sqlite.php). The SQLite example uses separate synthetic in-memory databases, enables actual foreign-key enforcement, preserves a pre-existing target row, and verifies repeat execution.

## API and boundaries

| API | Meaning |
| --- | --- |
| `Entity(name, sourceKey, fields, references = [], dependsOn = [])` | Declare ordered source-key columns, output fields, references, and optional ordering dependencies. Field/reference target columns must be distinct. |
| `Field::from(source, transform = null)` | Read a column, optionally invoking `(mixed $value, array $row)`. A missing column fails; a present null remains null. |
| `Field::value(value)` | A constant scalar or null. |
| `Reference(entity, sourceFields, missing = Fail)` | Resolve the parent's old key to its new ID. Composite-key positions and arity must match the parent's `sourceKey`. |
| `MigrationPlan(scope, Entity ...entities)` | Validate and sort the full dependency graph at construction. Available entities use declaration order to break ties. Cycles, self-references, unknown dependencies and key-arity mismatches fail before I/O. |
| `Migrator(source, target, maps)->run(plan)` | Fail-fast execution returning `MigrationReport`, or throwing `MigrationFailed` with a partial report. |
| `SourceKey::fromValues([...])` / `fromRow(fields, row)` | Typed ordered identity: int and UTF-8 string only. `7` differs from `"7"`; zero and empty string are valid. |
| `IdMapStore::get(scope, entity, key)` / `put(..., Mapping)` | Scope/entity-isolated immutable target ID and SHA-256 output fingerprint. Repeating the same mapping succeeds; changing ID or digest fails. |

Entity, field, table and column identifiers match `[A-Za-z_][A-Za-z0-9_]{0,127}`. A scope is a non-sensitive stable identifier of 1–128 alphanumeric/underscore/dot/colon/hyphen characters, beginning with an alphanumeric or underscore. Target IDs are integers or non-empty UTF-8 strings. Output values must be null, boolean, integer, finite float, or UTF-8 string. Explicitly transform binary or structured data to suitable strings.

`MissingReferencePolicy::Fail` is the default. `SetNull` sets the target foreign key to null and requires a nullable target schema. `SkipRow` skips the entire child row without target or mapping writes for that row. An absent parent mapping or any null composite-key component invokes the policy. A missing source column always fails; non-null keys must satisfy key type constraints. Skipped parents are not automatically created or inferred from target business tables.

## Failure recovery and repeat execution

Each row follows: extract key → transform fields and resolve references → fingerprint output → look up mapping → idempotent target write → persist mapping. Even mapped rows recompute their output; changed fields fail rather than updating existing targets. Fingerprints sort output column names and preserve scalar type distinctions. Float fingerprints use IEEE-754 bytes independently of PHP serialize_precision.

`TargetWriter::write(scope, entity, key, fields)` must **atomically persist the target row and its durable idempotency receipt on the target side**. A replay of the same operation identity and fields must return the original ID with `inserted=false`. Different fields must fail. An unprotected INSERT does not meet this interface's semantic contract.

`SqliteTargetWriter` inserts the application row and `_relational_migrator_receipts` entry in a single PDO transaction. Provided the committed database records remain intact, a replay recovers the original ID from the receipt and fills the map after target commit followed by process exit, a lost response, or map persistence failure. If the map was saved before a failure was reported, the replay counts as `alreadyMapped` instead. The map and target are not presented as a distributed transaction.

There is no general exactly-once guarantee across arbitrary databases. A custom writer that cannot meet the atomic target-side contract is unsuitable for automatic safe retries. Review its engine, transaction behavior, and external effects. Injected test failures verify specific paths; they are not production crash, power-loss, or all-driver guarantees.

Required operating conditions:

- Reuse a scope only for the same stable source snapshot, key types, deterministic transforms, reference definitions, and destination. Keep callbacks pure: they run again on mapped rows. A fresh scope creates an independent import and therefore new rows.
- Preserve target rows, maps, and receipts together. Do not independently delete or edit them. Do not reuse a scope for another data source or target configuration.
- Use one executor per scope. Lock conflicts fail; explicitly retry after releasing the lock. No built-in concurrent scheduling, backoff, batching, or global checkpoints are provided.
- Supply a stable source snapshot. SQLite examples use separate source and target databases. Do not stream from the same SQLite database file or application table being written. Use a dedicated read transaction on a separate source connection or an offline copy when a snapshot is required.

There is no business-data dry run, whole-migration rollback, automatic deletion, update/synchronization, or compensation API. `orderedEntities()` inspects graph order without I/O; it does not validate individual source rows. SQLite rollback covers only an uncommitted row and its receipt; previously committed rows remain.

## SQLite/PDO adapters

```php
use Lfcheng92\RelationalMigrator\Adapter\Sqlite\{
    SqliteSourceReader, SqliteTargetWriter, SqliteIdMapStore, SqliteTable
};
// Separate source and target datasets; both PDO connections use ERRMODE_EXCEPTION.
$reader = new SqliteSourceReader($sourcePdo, ['authors' => 'old_authors']);
$writer = new SqliteTargetWriter($targetPdo, ['authors' => new SqliteTable('authors', 'id')]);
$maps = new SqliteIdMapStore($targetPdo);
$writer->initialize();
$maps->initialize();
$report = (new Migrator($reader, $writer, $maps))->run($authorsPlan);
```

Constructors check connection settings and SQLite version without schema writes. Explicit `initialize()` creates only the package's helper table. Application tables must already exist, with a single-column primary key generated by the database. The generated ID must be a non-null integer/string; explicit ID field assignment is rejected. The writer uses `INSERT ... RETURNING`. Internal helper tables cannot be targets, and output columns must also be unique ignoring case.

The writer owns each target transaction and rejects active outer transactions without committing or rolling them back. Application rows and receipts must share a SQLite database. Target writes, helper initialization and every map save recheck journal_mode and accept only DELETE / TRUNCATE / PERSIST / MEMORY / WAL. OFF and unknown modes fail before mutation, including on reused connections changed after construction. Do not use triggers that change returned primary keys, remove/rewrite receipts, or create external effects. Target affinities, constraints and trigger behavior remain the caller's responsibility; source column types and target schemas are not copied or altered.

**In-process rollback is different from crash/power-loss durability.** Journal checks and injected failures verify rollback/retry while the process is alive. The library does not change journal_mode, synchronous, hardware or filesystem settings, and does not test power-loss recovery. MEMORY journals live in RAM: they are suitable for disposable test data, but a file database may be corrupted by a process crash mid-transaction in that mode. A `:memory:` database disappears when its connection/process ends. Disk durability additionally depends on synchronization, the OS, storage and the fault model; synchronous=OFF does not provide a power-loss durability guarantee. Applications needing recoverable imports must choose suitable disk-journal/sync settings and separately validate their environment. See the SQLite [journal_mode](https://www.sqlite.org/pragma.html#pragma_journal_mode) and [synchronous](https://www.sqlite.org/pragma.html#pragma_synchronous) documentation.

`_relational_migrator_id_map` persists source-key JSON, typed target-ID JSON, and fingerprints. Old IDs and receipts can be sensitive; protect database files and backups. See [SECURITY.md](SECURITY.md).

## Reports and exceptions

`MigrationReport` exposes `completed`, topologically ordered `entities`, `totals()`, and JSON serialization. Counters:

| Counter | Meaning |
| --- | --- |
| `seen` | Rows yielded, including the current failed/skipped row. |
| `inserted` | A new target insert whose mapping was also successfully saved in this invocation. |
| `recovered` | An existing target receipt recovered and its map successfully saved. |
| `alreadyMapped` | An existing mapping with an identical current output fingerprint. |
| `skipped` | A row skipped through the explicit reference policy. |
| `failed` | A failed record or source iteration failure. A source iteration failure may add no seen row. |

A target commit followed by a map failure is not counted as inserted. `MigrationFailed::targetMayHaveCommitted` conservatively describes the failing row; the report is not an authoritative target row count after failure. The exception also exposes `report`, `entity`, `stage` (`record`, `map_lookup`, `target_write`, `map_save`, `source_read`), and `getPrevious()`. Reports omit source IDs, row data and original error text; raw driver errors and traces can still contain sensitive information.

Configuration problems use `ConfigurationException`; record problems use `RecordException`, including `MappingConflict` and `MissingReferenceException`. Execution errors are wrapped in `MigrationFailed` with their cause and partial report.

## Validation

```sh
composer install --no-interaction --prefer-dist --no-plugins --no-scripts
composer validate --strict
composer check
php examples/memory.php
php examples/sqlite.php
php tools/consumer-check.php
```

`composer check` runs syntax validation, PHPStan max, and PHPUnit. The consumer verifier archives and scans the source, extracts a vendor-free snapshot, disables networking and Packagist, installs a mirrored Composer path dependency without dev dependencies, removes the snapshot, and starts an independent PHP process. It verifies composite keys, actual SQLite foreign keys, recovery after target commit/map failure, and repeat execution, then cleans its temporary files. Set `COMPOSER_BINARY` to a local Composer executable or phar if Composer is not on PATH.

Actual local results are recorded in [docs/LOCAL_VALIDATION.md](docs/LOCAL_VALIDATION.md). CI is configured for PHP 8.2/8.3/8.4/8.5; it has not run without a remote repository and is not evidence of an already verified version matrix.

## Release boundaries

See [CHANGELOG.md](CHANGELOG.md) and [docs/RELEASE_CHECKLIST.md](docs/RELEASE_CHECKLIST.md). Local validation, later private upload, and any future public release are separate gates. Passing tests never triggers a public repository, an open-source license, or a Packagist release.

Primary design references: [Composer schema](https://getcomposer.org/doc/04-schema.md), [PDO transactions](https://www.php.net/manual/en/pdo.transactions.php), [SQLite transactions](https://www.sqlite.org/lang_transaction.html).
