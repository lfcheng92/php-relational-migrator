<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator;
use Lfcheng\RelationalMigrator\Exception\ConfigurationException;
use Lfcheng\RelationalMigrator\Internal\Rules;
final class Entity
{
    /** @param list<string> $sourceKey
     * @param array<string, Field> $fields
     * @param array<string, Reference> $references
     * @param list<string> $dependsOn Additional ordering dependencies.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $sourceKey,
        public readonly array $fields,
        public readonly array $references = [],
        public readonly array $dependsOn = [],
    ) {
        Rules::identifier($name);
        Rules::keyFields($sourceKey);
        foreach ($fields as $column => $field) {
            if (!is_string($column) || !$field instanceof Field) {
                throw new ConfigurationException('Fields must map target column names to Field objects.');
            }
            Rules::identifier($column);
        }
        foreach ($references as $column => $reference) {
            if (!is_string($column) || !$reference instanceof Reference || array_key_exists($column, $fields)) {
                throw new ConfigurationException('References need distinct target column names and Reference objects.');
            }
            Rules::identifier($column);
        }
        if (!array_is_list($dependsOn)) {
            throw new ConfigurationException('dependsOn must be an ordered list.');
        }
        foreach ($dependsOn as $dependency) { Rules::identifier($dependency); }
    }
    /** @return list<string> */
    public function dependencies(): array
    {
        return array_values(array_unique(array_merge(
            $this->dependsOn,
            array_map(static fn(Reference $reference): string => $reference->entity, array_values($this->references)),
        )));
    }
}
