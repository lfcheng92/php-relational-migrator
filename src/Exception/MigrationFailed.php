<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator\Exception;
use Lfcheng\RelationalMigrator\MigrationReport;
final class MigrationFailed extends \RuntimeException
{
    public function __construct(
        public readonly MigrationReport $report,
        public readonly string $entity,
        public readonly string $stage,
        public readonly bool $targetMayHaveCommitted,
        \Throwable $previous,
    ) {
        parent::__construct("Migration failed in entity '$entity' during $stage.", 0, $previous);
    }
}
