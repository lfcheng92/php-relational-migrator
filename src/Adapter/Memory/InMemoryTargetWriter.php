<?php
declare(strict_types=1);
namespace Lfcheng92\RelationalMigrator\Adapter\Memory;
use Lfcheng92\RelationalMigrator\{Entity, Mapping, SourceKey, WriteResult, Contract\TargetWriter};
use Lfcheng92\RelationalMigrator\Exception\MappingConflict;
use Lfcheng92\RelationalMigrator\Internal\Data;
final class InMemoryTargetWriter implements TargetWriter
{
    /** @var array<string, Mapping> */
    private array $receipts = [];
    /** @var array<string, array<int, array<string, bool|float|int|string|null>>> */
    private array $rows = [];
    public function write(string $scope, Entity $entity, SourceKey $key, array $fields): WriteResult
    {
        $index = json_encode([$scope, $entity->name, $key->encode()], JSON_THROW_ON_ERROR);
        $digest = Data::fingerprint(Data::validate($fields));
        if (isset($this->receipts[$index])) {
            $mapping = $this->receipts[$index];
            if ($mapping->fingerprint !== $digest) { throw new MappingConflict('Idempotent replay has changed fields.'); }
            return new WriteResult($mapping->targetId, false);
        }
        $id = count($this->rows[$entity->name] ?? []) + 1;
        $this->rows[$entity->name][$id] = $fields;
        $this->receipts[$index] = new Mapping($id, $digest);
        return new WriteResult($id, true);
    }
    /** @return array<int, array<string, bool|float|int|string|null>> */
    public function rows(string $entity): array { return $this->rows[$entity] ?? []; }
}
