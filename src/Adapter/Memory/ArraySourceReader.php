<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator\Adapter\Memory;
use Lfcheng\RelationalMigrator\{Entity, Contract\SourceReader};
use Lfcheng\RelationalMigrator\Exception\ConfigurationException;
final class ArraySourceReader implements SourceReader
{
    /** @param array<string, list<array<string, mixed>>> $rows */
    public function __construct(private readonly array $rows) {}
    public function read(Entity $entity): iterable
    {
        if (!array_key_exists($entity->name, $this->rows)) { throw new ConfigurationException('No source registered for entity.'); }
        yield from $this->rows[$entity->name];
    }
}
