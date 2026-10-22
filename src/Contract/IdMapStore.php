<?php
declare(strict_types=1);
namespace Lfcheng92\RelationalMigrator\Contract;
use Lfcheng92\RelationalMigrator\{Mapping, SourceKey};
interface IdMapStore
{
    public function get(string $scope, string $entity, SourceKey $key): ?Mapping;
    /** Immutable put: the same mapping is accepted; any differing ID/digest must fail. */
    public function put(string $scope, string $entity, SourceKey $key, Mapping $mapping): void;
}
