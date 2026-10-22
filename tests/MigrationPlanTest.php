<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator\Tests;
use Lfcheng\RelationalMigrator\{Entity, Field, MigrationPlan, Reference};
use Lfcheng\RelationalMigrator\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class MigrationPlanTest extends TestCase
{
    public function testOrderUsesDependenciesAndOriginalDeclarationToBreakTies(): void
    {
        $plan = new MigrationPlan('ordering',
            new Entity('child', ['id'], [], ['parent_id' => new Reference('parent', ['p'])]),
            new Entity('parent', ['id'], []),
            new Entity('unrelated', ['id'], []),
            new Entity('last', ['id'], [], dependsOn: ['unrelated', 'child']),
        );
        self::assertSame(['parent', 'child', 'unrelated', 'last'], array_map(fn(Entity $e) => $e->name, $plan->orderedEntities()));
    }
    public static function invalidPlans(): iterable
    {
        yield 'empty' => [fn() => new MigrationPlan('a')];
        yield 'duplicate' => [fn() => new MigrationPlan('a', new Entity('e', ['id'], []), new Entity('e', ['id'], []))];
        yield 'unknown explicit dependency' => [fn() => new MigrationPlan('a', new Entity('e', ['id'], [], dependsOn: ['absent']))];
        yield 'unknown reference' => [fn() => new MigrationPlan('a', new Entity('e', ['id'], [], ['p' => new Reference('absent', ['id'])]))];
        yield 'self cycle' => [fn() => new MigrationPlan('a', new Entity('e', ['id'], [], dependsOn: ['e']))];
        yield 'two entity cycle' => [fn() => new MigrationPlan('a', new Entity('e', ['id'], [], dependsOn: ['f']), new Entity('f', ['id'], [], dependsOn: ['e']))];
        yield 'reference arity' => [fn() => new MigrationPlan('a', new Entity('p', ['a', 'b'], []), new Entity('c', ['id'], [], ['p' => new Reference('p', ['a'])]))];
        yield 'invalid scope' => [fn() => new MigrationPlan("bad\0scope", new Entity('e', ['id'], []))];
        yield 'empty source key' => [fn() => new Entity('e', [], [])];
        yield 'duplicate source key' => [fn() => new Entity('e', ['id', 'id'], [])];
        yield 'invalid identifier' => [fn() => new Entity('e;drop', ['id'], [])];
        yield 'target field reference overlap' => [fn() => new Entity('e', ['id'], ['p' => Field::value(1)], ['p' => new Reference('a', ['id'])])];
        yield 'invalid target field' => [fn() => new Entity('e', ['id'], ['bad field' => Field::value(1)])];
    }
    #[DataProvider('invalidPlans')]
    public function testRejectsInvalidPlansBeforeAnyIo(\Closure $build): void
    {
        $this->expectException(ConfigurationException::class);
        $build();
    }
}
