<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator\Adapter\Sqlite;
use Lfcheng\RelationalMigrator\Exception\{ConfigurationException, RecordException};
use Lfcheng\RelationalMigrator\Internal\Rules;
/** @internal */
final class Sqlite
{
    public const RECEIPTS = '_relational_migrator_receipts';
    public const MAPS = '_relational_migrator_id_map';
    public static function check(\PDO $pdo): void
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite'
            || $pdo->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION) {
            throw new ConfigurationException('SQLite adapters require PDO SQLite with ERRMODE_EXCEPTION.');
        }
        $statement = self::query($pdo, 'SELECT sqlite_version()');
        $version = $statement->fetchColumn();
        $statement->closeCursor();
        if (!is_string($version) || version_compare($version, '3.35.0', '<')) {
            throw new ConfigurationException('SQLite 3.35.0+ is required for INSERT RETURNING.');
        }
    }
    public static function requireJournal(\PDO $pdo): void
    {
        $statement = self::query($pdo, 'PRAGMA journal_mode');
        $mode = $statement->fetchColumn();
        $statement->closeCursor();
        // This guard covers rollback while the process is alive, not power-loss durability.
        if (!is_string($mode) || !in_array(strtolower($mode), ['delete', 'truncate', 'persist', 'memory', 'wal'], true)) {
            throw new ConfigurationException('SQLite writes require a rollback-capable journal mode; OFF or unknown modes are unsupported.');
        }
    }
    public static function query(\PDO $pdo, string $sql): \PDOStatement
    {
        $statement = $pdo->query($sql);
        if ($statement === false) { throw new RecordException('SQLite query failed.'); }
        return $statement;
    }
    public static function quote(string $identifier): string
    {
        Rules::identifier($identifier);
        return '"' . $identifier . '"';
    }
    /** @param list<bool|float|int|string|null> $values */
    public static function execute(\PDOStatement $statement, array $values): void
    {
        try {
            foreach ($values as $index => $value) {
                $type = match (true) {
                    $value === null => \PDO::PARAM_NULL,
                    is_int($value) => \PDO::PARAM_INT,
                    is_bool($value) => \PDO::PARAM_BOOL,
                    default => \PDO::PARAM_STR,
                };
                // PDO has no float parameter type. Use an explicit round-trip, locale-independent decimal.
                $bound = is_float($value) ? sprintf('%.17H', $value) : $value;
                $statement->bindValue($index + 1, $bound, $type);
            }
            $statement->execute();
        } catch (\Throwable $error) {
            // PDO exceptions may retain statement arguments in their stack traces.
            // Finalize a failed SQLite statement before rollback/retry on this connection.
            try { $statement->closeCursor(); } catch (\Throwable) { /* Preserve the original failure. */ }
            throw $error;
        }
    }

    public static function decodeId(mixed $encoded): int|string
    {
        if (!is_string($encoded)) { throw new RecordException('Invalid persisted target ID.'); }
        $id = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        if (!is_int($id) && !is_string($id)) { throw new RecordException('Invalid persisted target ID type.'); }
        return $id;
    }
}
