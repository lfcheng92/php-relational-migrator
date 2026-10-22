<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator\Tests;
use Lfcheng\RelationalMigrator\{Entity, Field, Mapping, MigrationPlan, Migrator, MissingReferencePolicy, Reference, SourceKey};
use Lfcheng\RelationalMigrator\Adapter\Memory\{ArraySourceReader, InMemoryIdMapStore, InMemoryTargetWriter};
use Lfcheng\RelationalMigrator\Contract\SourceReader;
use Lfcheng\RelationalMigrator\Exception\{MappingConflict, MigrationFailed, MissingReferenceException, RecordException};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class MigratorTest extends TestCase
{
    private function plan(): MigrationPlan { return new MigrationPlan('test-v1', new Entity('items', ['old_id'], ['label' => Field::from('label')])); }
    private function source(array $rows = [['old_id' => 5, 'label' => 'demo']]): ArraySourceReader { return new ArraySourceReader(['items' => $rows]); }
    public function testCompositeKeysTransformsAndCrossEntityReferences(): void
    {
        $source = new ArraySourceReader([
            'parents' => [['tenant' => 'a', 'old_id' => 7, 'label' => '  One  '], ['tenant' => 'b', 'old_id' => 7, 'label' => 'Two']],
            'children' => [['old_id' => 99, 'tenant' => 'b', 'parent' => 7]],
        ]);
        $writer = new InMemoryTargetWriter(); $maps = new InMemoryIdMapStore();
        $plan = new MigrationPlan('composite-v1',
            new Entity('children', ['old_id'], ['active' => Field::value(true)], ['parent_id' => new Reference('parents', ['tenant', 'parent'])]),
            new Entity('parents', ['tenant', 'old_id'], ['label' => Field::from('label', static fn($value, $row) => trim($value) . $row['tenant'])]),
        );
        $report = (new Migrator($source, $writer, $maps))->run($plan);
        self::assertSame(3, $report->totals()['inserted']);
        self::assertSame(['label' => 'Onea'], $writer->rows('parents')[1]);
        self::assertSame(['active' => true, 'parent_id' => 2], $writer->rows('children')[1]);
        self::assertSame(2, $maps->get('composite-v1', 'parents', SourceKey::fromValues(['b', 7]))?->targetId);
    }
    public function testRepeatAndDuplicateIdenticalRowsAreSafe(): void
    {
        $writer = new InMemoryTargetWriter(); $maps = new InMemoryIdMapStore();
        $source = $this->source([['old_id' => 5, 'label' => 'demo'], ['old_id' => 5, 'label' => 'demo']]);
        $migrator = new Migrator($source, $writer, $maps);
        self::assertSame(1, $migrator->run($this->plan())->totals()['inserted']);
        self::assertSame(2, $migrator->run($this->plan())->totals()['alreadyMapped']);
        self::assertCount(1, $writer->rows('items'));
    }
    public function testNewScopeCreatesSeparateDestinationRows(): void
    {
        $writer = new InMemoryTargetWriter(); $maps = new InMemoryIdMapStore();
        $migrator = new Migrator($this->source(), $writer, $maps);
        $migrator->run($this->plan());
        $migrator->run(new MigrationPlan('new-snapshot', ...$this->plan()->orderedEntities()));
        self::assertCount(2, $writer->rows('items'));
    }
    public function testChangedFieldsForMappedKeyFailWithoutMutatingTarget(): void
    {
        $writer = new InMemoryTargetWriter(); $maps = new InMemoryIdMapStore();
        (new Migrator($this->source(), $writer, $maps))->run($this->plan());
        try { (new Migrator($this->source([['old_id' => 5, 'label' => 'changed']]), $writer, $maps))->run($this->plan()); self::fail('Expected conflict.'); }
        catch (MigrationFailed $error) { self::assertInstanceOf(MappingConflict::class, $error->getPrevious()); self::assertFalse($error->targetMayHaveCommitted); }
        self::assertSame([1 => ['label' => 'demo']], $writer->rows('items'));
    }
    public function testEmptySourceCompletes(): void
    {
        $report = (new Migrator($this->source([]), new InMemoryTargetWriter(), new InMemoryIdMapStore()))->run($this->plan());
        self::assertTrue($report->completed); self::assertSame(0, $report->totals()['seen']);
    }
    public static function missingPolicies(): iterable
    {
        foreach (MissingReferencePolicy::cases() as $policy) {
            yield $policy->value . ' unknown key' => [$policy, 404];
            yield $policy->value . ' null key' => [$policy, null];
        }
    }
    #[DataProvider('missingPolicies')]
    public function testMissingReferencePolicies(MissingReferencePolicy $policy, ?int $foreign): void
    {
        $writer = new InMemoryTargetWriter();
        $plan = new MigrationPlan('reference', new Entity('parents', ['id'], []), new Entity('children', ['id'], [], ['parent_id' => new Reference('parents', ['parent'], $policy)]));
        $migrator = new Migrator(new ArraySourceReader(['parents' => [], 'children' => [['id' => 1, 'parent' => $foreign]]]), $writer, new InMemoryIdMapStore());
        if ($policy === MissingReferencePolicy::Fail) {
            try { $migrator->run($plan); self::fail('Expected missing reference.'); }
            catch (MigrationFailed $error) { self::assertInstanceOf(MissingReferenceException::class, $error->getPrevious()); self::assertSame(1, $error->report->totals()['failed']); }
        } else {
            $report = $migrator->run($plan);
            self::assertSame($policy === MissingReferencePolicy::SkipRow ? 1 : 0, $report->totals()['skipped']);
            self::assertSame($policy === MissingReferencePolicy::SetNull ? [1 => ['parent_id' => null]] : [], $writer->rows('children'));
        }
    }
    public function testMissingReferenceColumnIsNotTreatedAsNullableData(): void
    {
        $plan = new MigrationPlan('reference', new Entity('parents', ['id'], []), new Entity('children', ['id'], [], ['parent_id' => new Reference('parents', ['absent'], MissingReferencePolicy::SetNull)]));
        $this->expectException(MigrationFailed::class);
        (new Migrator(new ArraySourceReader(['parents' => [], 'children' => [['id' => 1]]]), new InMemoryTargetWriter(), new InMemoryIdMapStore()))->run($plan);
    }
    public function testInvalidComponentInPartiallyNullReferenceStillFails(): void
    {
        $plan = new MigrationPlan('reference', new Entity('parents', ['tenant', 'id'], []), new Entity('children', ['id'], [], ['parent_id' => new Reference('parents', ['tenant', 'parent'], MissingReferencePolicy::SetNull)]));
        $source = new ArraySourceReader(['parents' => [], 'children' => [['id' => 1, 'tenant' => null, 'parent' => true]]]);
        try { (new Migrator($source, new InMemoryTargetWriter(), new InMemoryIdMapStore()))->run($plan); self::fail('Expected invalid key component.'); }
        catch (MigrationFailed $error) { self::assertInstanceOf(RecordException::class, $error->getPrevious()); }
    }
    public static function invalidRows(): iterable
    {
        yield 'missing key' => [['label' => 'x']];
        yield 'null key' => [['old_id' => null, 'label' => 'x']];
        yield 'missing field' => [['old_id' => 1]];
        yield 'array output' => [['old_id' => 1, 'label' => []]];
        yield 'object output' => [['old_id' => 1, 'label' => new \stdClass()]];
        yield 'infinite output' => [['old_id' => 1, 'label' => INF]];
        yield 'nan output' => [['old_id' => 1, 'label' => NAN]];
        yield 'invalid utf8 output' => [['old_id' => 1, 'label' => "\xB1\x31"]];
    }
    #[DataProvider('invalidRows')]
    public function testInvalidRowsFailBeforeTargetWrite(array $row): void
    {
        $writer = new InMemoryTargetWriter();
        try { (new Migrator($this->source([$row]), $writer, new InMemoryIdMapStore()))->run($this->plan()); self::fail('Expected invalid row.'); }
        catch (MigrationFailed $error) { self::assertInstanceOf(RecordException::class, $error->getPrevious()); self::assertFalse($error->targetMayHaveCommitted); self::assertSame(1, $error->report->totals()['failed']); }
        self::assertSame([], $writer->rows('items'));
    }
    public function testZeroKeyAndNullTargetValueAreAllowed(): void
    {
        $writer = new InMemoryTargetWriter();
        (new Migrator($this->source([['old_id' => 0, 'label' => null]]), $writer, new InMemoryIdMapStore()))->run($this->plan());
        self::assertSame([1 => ['label' => null]], $writer->rows('items'));
    }
    public function testTransformExceptionIsChainedAndReportHasNoRowData(): void
    {
        $plan = new MigrationPlan('transform', new Entity('items', ['old_id'], ['label' => Field::from('label', static function () { throw new \DomainException('Injected transform failure.'); })]));
        try { (new Migrator($this->source([['old_id' => 909, 'label' => 'synthetic-private-marker']]), new InMemoryTargetWriter(), new InMemoryIdMapStore()))->run($plan); self::fail('Expected transform failure.'); }
        catch (MigrationFailed $error) { self::assertInstanceOf(\DomainException::class, $error->getPrevious()); self::assertStringNotContainsString('synthetic-private-marker', json_encode($error->report)); self::assertStringNotContainsString('909', json_encode($error->report)); }
    }
    public function testSourceFailureAfterOneCommitHasPartialReport(): void
    {
        $source = new class implements SourceReader {
            public function read(Entity $entity): iterable { yield ['old_id' => 1, 'label' => 'x']; throw new \RuntimeException('Injected source read failure.'); }
        };
        $writer = new InMemoryTargetWriter();
        try { (new Migrator($source, $writer, new InMemoryIdMapStore()))->run($this->plan()); self::fail('Expected read failure.'); }
        catch (MigrationFailed $error) { self::assertSame('source_read', $error->stage); self::assertSame(1, $error->report->totals()['inserted']); self::assertSame(1, $error->report->totals()['seen']); self::assertSame(1, $error->report->totals()['failed']); }
        self::assertCount(1, $writer->rows('items'));
    }
    public function testMissingSourceFailsWithSourceReadStage(): void
    {
        try { (new Migrator(new ArraySourceReader([]), new InMemoryTargetWriter(), new InMemoryIdMapStore()))->run($this->plan()); self::fail('Expected missing source.'); }
        catch (MigrationFailed $error) { self::assertSame('source_read', $error->stage); self::assertFalse($error->targetMayHaveCommitted); }
    }
    public function testMalformedReaderRowIsRejectedAtBoundary(): void
    {
        $source = new class implements SourceReader { public function read(Entity $entity): iterable { yield 'not-an-array'; } };
        $this->expectException(MigrationFailed::class);
        (new Migrator($source, new InMemoryTargetWriter(), new InMemoryIdMapStore()))->run($this->plan());
    }
    public static function mapFailures(): iterable { yield 'before persistence' => [false]; yield 'after persistence' => [true]; }
    #[DataProvider('mapFailures')]
    public function testMapFailureRecoversWithoutDuplicate(bool $afterSave): void
    {
        $writer = new InMemoryTargetWriter(); $maps = new InMemoryIdMapStore();
        $migrator = new Migrator($this->source(), $writer, new FaultIdMapStore($maps, $afterSave));
        try { $migrator->run($this->plan()); self::fail('Expected map failure.'); }
        catch (MigrationFailed $error) { self::assertSame('map_save', $error->stage); self::assertTrue($error->targetMayHaveCommitted); self::assertSame(0, $error->report->totals()['inserted']); }
        $report = $migrator->run($this->plan());
        self::assertSame($afterSave ? 1 : 0, $report->totals()['alreadyMapped']);
        self::assertSame($afterSave ? 0 : 1, $report->totals()['recovered']);
        self::assertCount(1, $writer->rows('items'));
    }
    public static function targetFailures(): iterable { yield 'before commit' => [false]; yield 'after commit' => [true]; }
    #[DataProvider('targetFailures')]
    public function testTargetFailureRecoveryDoesNotDuplicate(bool $afterCommit): void
    {
        $writer = new InMemoryTargetWriter(); $maps = new InMemoryIdMapStore();
        $migrator = new Migrator($this->source(), new FaultTargetWriter($writer, $afterCommit), $maps);
        try { $migrator->run($this->plan()); self::fail('Expected write failure.'); }
        catch (MigrationFailed $error) { self::assertSame('target_write', $error->stage); self::assertTrue($error->targetMayHaveCommitted); }
        self::assertSame($afterCommit ? 1 : 0, $migrator->run($this->plan())->totals()['recovered']);
        self::assertCount(1, $writer->rows('items'));
    }
    public function testFloatFingerprintDoesNotDependOnSerializePrecision(): void
    {
        $previous = ini_set('serialize_precision', '3');
        try {
            $writer = new InMemoryTargetWriter(); $maps = new InMemoryIdMapStore();
            (new Migrator($this->source([['old_id' => 1, 'label' => 1.234567891234567]]), $writer, $maps))->run($this->plan());
            try { (new Migrator($this->source([['old_id' => 1, 'label' => 1.234567891234569]]), $writer, $maps))->run($this->plan()); self::fail('Expected changed float conflict.'); }
            catch (MigrationFailed $error) { self::assertInstanceOf(MappingConflict::class, $error->getPrevious()); }
        } finally { ini_set('serialize_precision', $previous); }
    }
    public function testMemoryMappingIsImmutableAndScopeSpecific(): void
    {
        $maps = new InMemoryIdMapStore(); $key = SourceKey::fromValues([1]); $mapping = new Mapping(9, str_repeat('a', 64));
        $maps->put('a', 'items', $key, $mapping); $maps->put('a', 'items', $key, $mapping);
        self::assertNull($maps->get('b', 'items', $key));
        $this->expectException(MappingConflict::class);
        $maps->put('a', 'items', $key, new Mapping('9', str_repeat('a', 64)));
    }
    public function testMemoryReceiptRejectsChangedFieldsWhenMapIsMissing(): void
    {
        $writer = new InMemoryTargetWriter(); $entity = $this->plan()->orderedEntities()[0]; $key = SourceKey::fromValues([1]);
        $writer->write('a', $entity, $key, ['label' => 'first']);
        $this->expectException(MappingConflict::class);
        $writer->write('a', $entity, $key, ['label' => 'changed']);
    }
}
