<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator;
use Lfcheng\RelationalMigrator\Exception\RecordException;
use Lfcheng\RelationalMigrator\Internal\Data;
final class Mapping
{
    public function __construct(public readonly int|string $targetId, public readonly string $fingerprint)
    {
        Data::id($targetId);
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint)) { throw new RecordException('Invalid SHA-256 fingerprint.'); }
    }
    public function equals(self $other): bool
    {
        return $this->targetId === $other->targetId && $this->fingerprint === $other->fingerprint;
    }
}
