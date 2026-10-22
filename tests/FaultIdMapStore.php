<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator\Tests;
use Lfcheng\RelationalMigrator\{Mapping, SourceKey, Contract\IdMapStore};
final class FaultIdMapStore implements IdMapStore
{
    private bool $armed = true;
    public function __construct(private IdMapStore $delegate, private bool $afterSave = false) {}
    public function get(string $scope, string $entity, SourceKey $key): ?Mapping { return $this->delegate->get($scope, $entity, $key); }
    public function put(string $scope, string $entity, SourceKey $key, Mapping $mapping): void
    {
        if (!$this->armed) { $this->delegate->put($scope, $entity, $key, $mapping); return; }
        $this->armed = false;
        if ($this->afterSave) { $this->delegate->put($scope, $entity, $key, $mapping); }
        throw new \RuntimeException('Injected map persistence failure.');
    }
}
