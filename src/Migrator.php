<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator;
use Lfcheng\RelationalMigrator\Contract\{IdMapStore, SourceReader, TargetWriter};
use Lfcheng\RelationalMigrator\Exception\{MappingConflict, MigrationFailed, MissingReferenceException, RecordException};
use Lfcheng\RelationalMigrator\Internal\Data;
final class Migrator
{
    public function __construct(
        private readonly SourceReader $source,
        private readonly TargetWriter $target,
        private readonly IdMapStore $maps,
    ) {}
    public function run(MigrationPlan $plan): MigrationReport
    {
        $counts = [];
        foreach ($plan->orderedEntities() as $entity) { $counts[$entity->name] = MigrationReport::emptyCounts(); }
        foreach ($plan->orderedEntities() as $entity) {
            $metrics = MigrationReport::emptyCounts();
            try {
                foreach ($this->source->read($entity) as $row) {
                    ++$metrics['seen'];
                    $stage = 'record';
                    $mayHaveCommitted = false;
                    try {
                        if (!is_array($row)) { throw new RecordException('SourceReader yielded a non-array row.'); }
                        $key = SourceKey::fromRow($entity->sourceKey, $row);
                        $fields = $this->resolve($plan->scope, $entity, $row);
                        if ($fields === null) { ++$metrics['skipped']; continue; }
                        $fingerprint = Data::fingerprint($fields);
                        $stage = 'map_lookup';
                        $existing = $this->maps->get($plan->scope, $entity->name, $key);
                        if ($existing !== null) {
                            if ($existing->fingerprint !== $fingerprint) { throw new MappingConflict('Mapped source key has changed target fields.'); }
                            ++$metrics['alreadyMapped'];
                            continue;
                        }
                        $stage = 'target_write';
                        $mayHaveCommitted = true;
                        $result = $this->target->write($plan->scope, $entity, $key, $fields);
                        $stage = 'map_save';
                        $this->maps->put($plan->scope, $entity->name, $key, new Mapping($result->targetId, $fingerprint));
                        if ($result->inserted) { ++$metrics['inserted']; }
                        else { ++$metrics['recovered']; }
                    } catch (\Throwable $error) {
                        ++$metrics['failed'];
                        $counts[$entity->name] = $metrics;
                        throw new MigrationFailed(new MigrationReport(false, $counts), $entity->name, $stage, $mayHaveCommitted, $error);
                    }
                }
            } catch (MigrationFailed $error) { throw $error; }
            catch (\Throwable $error) {
                ++$metrics['failed'];
                $counts[$entity->name] = $metrics;
                throw new MigrationFailed(new MigrationReport(false, $counts), $entity->name, 'source_read', false, $error);
            }
            $counts[$entity->name] = $metrics;
        }
        return new MigrationReport(true, $counts);
    }
    /** null means skip row, whereas [] is a valid default-only target row.
     * @param array<string, mixed> $row
     * @return array<string, bool|float|int|string|null>|null
     */
    private function resolve(string $scope, Entity $entity, array $row): ?array
    {
        $fields = [];
        foreach ($entity->fields as $column => $field) { $fields[$column] = $field->resolve($row); }
        foreach ($entity->references as $column => $reference) {
            $values = [];
            foreach ($reference->sourceFields as $sourceField) {
                if (!array_key_exists($sourceField, $row)) { throw new RecordException("Reference column '$sourceField' is absent."); }
                $value = $row[$sourceField];
                if ($value !== null && !is_int($value) && !is_string($value)) { throw new RecordException('Reference key components must be integers, strings, or null.'); }
                if (is_string($value) && preg_match('//u', $value) !== 1) { throw new RecordException('Reference key strings must be valid UTF-8.'); }
                $values[] = $value;
            }
            $mapping = in_array(null, $values, true) ? null : $this->maps->get($scope, $reference->entity, SourceKey::fromValues($values));
            if ($mapping === null) {
                if ($reference->missing === MissingReferencePolicy::SkipRow) { return null; }
                if ($reference->missing === MissingReferencePolicy::SetNull) { $fields[$column] = null; continue; }
                throw new MissingReferenceException("Unresolved reference to entity '{$reference->entity}'.");
            }
            $fields[$column] = $mapping->targetId;
        }
        return Data::validate($fields);
    }
}
