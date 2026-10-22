<?php
declare(strict_types=1);
namespace Lfcheng92\RelationalMigrator;
use Lfcheng92\RelationalMigrator\Exception\ConfigurationException;
use Lfcheng92\RelationalMigrator\Internal\Rules;
final class MigrationPlan
{
    /** @var list<Entity> */
    private readonly array $ordered;
    public function __construct(public readonly string $scope, Entity ...$entities)
    {
        Rules::scope($scope);
        if ($entities === []) { throw new ConfigurationException('A plan must contain at least one entity.'); }
        $remaining = [];
        foreach ($entities as $entity) {
            if (isset($remaining[$entity->name])) { throw new ConfigurationException('Duplicate entity name.'); }
            $remaining[$entity->name] = $entity;
        }
        foreach ($remaining as $entity) {
            foreach ($entity->dependencies() as $dependency) {
                if (!isset($remaining[$dependency])) { throw new ConfigurationException('Unknown entity dependency.'); }
            }
            foreach ($entity->references as $reference) {
                if (count($reference->sourceFields) !== count($remaining[$reference->entity]->sourceKey)) {
                    throw new ConfigurationException('Reference key arity must match its parent source key.');
                }
            }
        }
        $ordered = [];
        $done = [];
        while ($remaining !== []) {
            $ready = null;
            foreach ($remaining as $name => $entity) {
                if (array_diff($entity->dependencies(), array_keys($done)) === []) { $ready = $name; break; }
            }
            if ($ready === null) { throw new ConfigurationException('Dependency cycle detected; no writes were started.'); }
            $ordered[] = $remaining[$ready];
            $done[$ready] = true;
            unset($remaining[$ready]);
        }
        $this->ordered = $ordered;
    }
    /** Dependencies precede dependants; ties use original declaration order.
     * @return list<Entity>
     */
    public function orderedEntities(): array { return $this->ordered; }
}
