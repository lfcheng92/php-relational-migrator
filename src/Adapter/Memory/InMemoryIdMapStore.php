<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator\Adapter\Memory;
use Lfcheng\RelationalMigrator\{Mapping, SourceKey, Contract\IdMapStore};
use Lfcheng\RelationalMigrator\Exception\MappingConflict;
final class InMemoryIdMapStore implements IdMapStore
{
    /** @var array<string, Mapping> */
    private array $entries = [];
    public function get(string $scope, string $entity, SourceKey $key): ?Mapping
    {
        return $this->entries[$this->index($scope, $entity, $key)] ?? null;
    }
    public function put(string $scope, string $entity, SourceKey $key, Mapping $mapping): void
    {
        $existing = $this->get($scope, $entity, $key);
        if ($existing !== null && !$existing->equals($mapping)) { throw new MappingConflict('ID mapping is immutable.'); }
        $this->entries[$this->index($scope, $entity, $key)] = $mapping;
    }
    private function index(string $scope, string $entity, SourceKey $key): string
    {
        return json_encode([$scope, $entity, $key->encode()], JSON_THROW_ON_ERROR);
    }
}
