<?php
declare(strict_types=1);
namespace Lfcheng92\RelationalMigrator;
use Lfcheng92\RelationalMigrator\Internal\Data;
final class WriteResult
{
    public function __construct(public readonly int|string $targetId, public readonly bool $inserted)
    {
        Data::id($targetId);
    }
}
