<?php
declare(strict_types=1);
namespace Lfcheng92\RelationalMigrator\Contract;
use Lfcheng92\RelationalMigrator\Entity;
interface SourceReader
{
    /** Read a stable snapshot in a deterministic order. Must not mutate the source.
     * @return iterable<array<string, mixed>>
     */
    public function read(Entity $entity): iterable;
}
