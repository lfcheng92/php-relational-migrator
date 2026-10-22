<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator;
/** No row values, source IDs, SQL, or exception text are included in reports.
 * @phpstan-type Counts array{seen:int, inserted:int, recovered:int, alreadyMapped:int, skipped:int, failed:int}
 */
final class MigrationReport implements \JsonSerializable
{
    /** @param array<string, Counts> $entities */
    public function __construct(public readonly bool $completed, public readonly array $entities) {}
    /** @return Counts */
    public function totals(): array
    {
        $total = self::emptyCounts();
        foreach ($this->entities as $counts) {
            foreach ($counts as $metric => $value) { $total[$metric] += $value; }
        }
        return $total;
    }
    /** @return Counts */
    public static function emptyCounts(): array
    {
        return ['seen' => 0, 'inserted' => 0, 'recovered' => 0, 'alreadyMapped' => 0, 'skipped' => 0, 'failed' => 0];
    }
    /** @return array{completed:bool, entities:array<string, Counts>, totals:Counts} */
    public function jsonSerialize(): array
    {
        return ['completed' => $this->completed, 'entities' => $this->entities, 'totals' => $this->totals()];
    }
}
