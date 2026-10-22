<?php
declare(strict_types=1);
namespace Lfcheng92\RelationalMigrator\Adapter\Sqlite;
use Lfcheng92\RelationalMigrator\Exception\ConfigurationException;
use Lfcheng92\RelationalMigrator\Internal\Rules;
final class SqliteTable
{
    public function __construct(public readonly string $name, public readonly string $idColumn = 'id')
    {
        Rules::identifier($name);
        Rules::identifier($idColumn);
        if (in_array(strtolower($name), [Sqlite::RECEIPTS, Sqlite::MAPS], true)) {
            throw new ConfigurationException('Internal helper tables cannot be used as targets.');
        }
    }
    public function identity(): string { return $this->name . ':' . $this->idColumn; }
}
