<?php
declare(strict_types=1);
namespace Lfcheng92\RelationalMigrator\Contract;
use Lfcheng92\RelationalMigrator\{Entity, SourceKey, WriteResult};
interface TargetWriter
{
    /** Atomically persist the target row AND its idempotency receipt.
     * Replays of (scope, entity, key) MUST return the original ID without another insert.
     * Reject replays with different fields. A throw may follow a committed write.
     * @param array<string, bool|float|int|string|null> $fields
     */
    public function write(string $scope, Entity $entity, SourceKey $key, array $fields): WriteResult;
}
