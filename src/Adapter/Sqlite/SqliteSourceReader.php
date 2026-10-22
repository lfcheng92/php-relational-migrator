<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator\Adapter\Sqlite;
use Lfcheng\RelationalMigrator\{Entity, Contract\SourceReader};
use Lfcheng\RelationalMigrator\Exception\ConfigurationException;
final class SqliteSourceReader implements SourceReader
{
    /** @param array<string, string> $tables entity name => source table */
    public function __construct(private readonly \PDO $pdo, private readonly array $tables)
    {
        Sqlite::check($pdo);
        foreach ($tables as $table) { Sqlite::quote($table); }
    }
    public function read(Entity $entity): iterable
    {
        $table = $this->tables[$entity->name] ?? throw new ConfigurationException('No SQLite source table configured.');
        $order = implode(', ', array_map(Sqlite::quote(...), $entity->sourceKey));
        $statement = Sqlite::query($this->pdo, 'SELECT * FROM ' . Sqlite::quote($table) . ' ORDER BY ' . $order);
        try {
            while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
                /** @var array<string, mixed> $row */
                yield $row;
            }
        } finally { $statement->closeCursor(); }
    }
}
