<?php
declare(strict_types=1);
namespace Lfcheng92\RelationalMigrator\Tests;
use Lfcheng92\RelationalMigrator\{Entity, SourceKey, WriteResult, Contract\TargetWriter};
final class FaultTargetWriter implements TargetWriter
{
    private bool $armed = true;
    public function __construct(private TargetWriter $delegate, private bool $afterCommit) {}
    public function write(string $scope, Entity $entity, SourceKey $key, array $fields): WriteResult
    {
        if (!$this->armed) { return $this->delegate->write($scope, $entity, $key, $fields); }
        $this->armed = false;
        if ($this->afterCommit) { $this->delegate->write($scope, $entity, $key, $fields); }
        throw new \RuntimeException('Injected target writer failure.');
    }
}
