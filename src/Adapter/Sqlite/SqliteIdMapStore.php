<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator\Adapter\Sqlite;
use Lfcheng\RelationalMigrator\{Mapping, SourceKey, Contract\IdMapStore};
use Lfcheng\RelationalMigrator\Exception\{MappingConflict, RecordException};
final class SqliteIdMapStore implements IdMapStore
{
    public function __construct(private readonly \PDO $pdo) { Sqlite::check($pdo); Sqlite::requireJournal($pdo); }
    /** Explicit setup, outside the migration. Does not create application tables. */
    public function initialize(): void
    {
        Sqlite::requireJournal($this->pdo);
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . Sqlite::quote(Sqlite::MAPS) . ' (
            scope TEXT NOT NULL, entity TEXT NOT NULL, source_key TEXT NOT NULL,
            target_id TEXT NOT NULL, fingerprint TEXT NOT NULL,
            PRIMARY KEY (scope, entity, source_key)
        )');
    }
    public function get(string $scope, string $entity, SourceKey $key): ?Mapping
    {
        $statement = $this->pdo->prepare('SELECT target_id, fingerprint FROM ' . Sqlite::quote(Sqlite::MAPS)
            . ' WHERE scope = ? AND entity = ? AND source_key = ?');
        Sqlite::execute($statement, [$scope, $entity, $key->encode()]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $statement->closeCursor();
        if ($row === false) { return null; }
        if (!is_string($row['fingerprint'])) { throw new RecordException('Invalid persisted fingerprint.'); }
        return new Mapping(Sqlite::decodeId($row['target_id']), $row['fingerprint']);
    }
    public function put(string $scope, string $entity, SourceKey $key, Mapping $mapping): void
    {
        Sqlite::requireJournal($this->pdo);
        $statement = $this->pdo->prepare('INSERT INTO ' . Sqlite::quote(Sqlite::MAPS)
            . ' (scope, entity, source_key, target_id, fingerprint) VALUES (?, ?, ?, ?, ?)'
            . ' ON CONFLICT (scope, entity, source_key) DO NOTHING');
        Sqlite::execute($statement, [$scope, $entity, $key->encode(), json_encode($mapping->targetId, JSON_THROW_ON_ERROR), $mapping->fingerprint]);
        $statement->closeCursor();
        $saved = $this->get($scope, $entity, $key);
        if ($saved === null || !$saved->equals($mapping)) { throw new MappingConflict('ID mapping is immutable.'); }
    }
}
