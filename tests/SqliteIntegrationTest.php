<?php
declare(strict_types=1);
namespace Lfcheng92\RelationalMigrator\Tests;
use Lfcheng92\RelationalMigrator\{Entity, Field, Mapping, MigrationPlan, Migrator, Reference, SourceKey};
use Lfcheng92\RelationalMigrator\Adapter\Memory\ArraySourceReader;
use Lfcheng92\RelationalMigrator\Adapter\Sqlite\{SqliteIdMapStore, SqliteSourceReader, SqliteTable, SqliteTargetWriter};
use Lfcheng92\RelationalMigrator\Exception\{ConfigurationException, MappingConflict, MigrationFailed, RecordException};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class SqliteIntegrationTest extends TestCase
{
    private array $temporaryFiles = [];
    private function db(?string $file = null): \PDO
    {
        return new \PDO('sqlite:' . ($file ?? ':memory:'), options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 0]);
    }
    private function temporaryDatabase(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'rm-isolated-');
        if ($file === false) { throw new \RuntimeException('Cannot create temporary test database.'); }
        $this->temporaryFiles[] = $file;
        return $file;
    }
    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            foreach ([$file, $file . '-wal', $file . '-shm', $file . '-journal'] as $path) { if (is_file($path)) { unlink($path); } }
        }
    }
    private function plan(): MigrationPlan { return new MigrationPlan('sqlite-v1', new Entity('items', ['old_id'], ['label' => Field::from('label')])); }
    private function source(array $rows = [['old_id' => 5, 'label' => 'demo']]): ArraySourceReader { return new ArraySourceReader(['items' => $rows]); }
    private function adapters(\PDO $pdo): array
    {
        $writer = new SqliteTargetWriter($pdo, ['items' => new SqliteTable('items')]);
        $maps = new SqliteIdMapStore($pdo);
        $writer->initialize(); $maps->initialize();
        return [$writer, $maps];
    }
    private function failure(callable $operation): MigrationFailed
    {
        try { $operation(); } catch (MigrationFailed $failure) { return $failure; }
        self::fail('Expected migration failure.');
    }
    public function testConstructorsHaveNoSchemaWriteSideEffects(): void
    {
        $db = $this->db();
        new SqliteSourceReader($db, []); new SqliteTargetWriter($db, []); new SqliteIdMapStore($db);
        self::assertSame(0, $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn());
    }
    public function testSqliteSourceOrderCompositeKeysAndRealForeignKeyRemapping(): void
    {
        $source = $this->db(); $target = $this->db();
        $source->exec("CREATE TABLE old_parents (tenant TEXT, old_id INTEGER, label TEXT, PRIMARY KEY (tenant, old_id));
            INSERT INTO old_parents VALUES ('b', 7, 'B'), ('a', 7, 'A');
            CREATE TABLE old_children (old_id INTEGER PRIMARY KEY, tenant TEXT, parent INTEGER, label TEXT);
            INSERT INTO old_children VALUES (5, 'b', 7, 'Child');");
        $target->exec("PRAGMA foreign_keys = ON;
            CREATE TABLE parents (id INTEGER PRIMARY KEY, label TEXT NOT NULL);
            CREATE TABLE children (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES parents(id), label TEXT);
            INSERT INTO parents VALUES (100, 'Existing');");
        $plan = new MigrationPlan('fk-v1',
            new Entity('children', ['old_id'], ['label' => Field::from('label')], ['parent_id' => new Reference('parents', ['tenant', 'parent'])]),
            new Entity('parents', ['tenant', 'old_id'], ['label' => Field::from('label')]),
        );
        $writer = new SqliteTargetWriter($target, ['parents' => new SqliteTable('parents'), 'children' => new SqliteTable('children')]);
        $maps = new SqliteIdMapStore($target); $writer->initialize(); $maps->initialize();
        $migrator = new Migrator(new SqliteSourceReader($source, ['parents' => 'old_parents', 'children' => 'old_children']), $writer, $maps);
        self::assertSame(3, $migrator->run($plan)->totals()['inserted']);
        self::assertSame(102, $target->query('SELECT parent_id FROM children')->fetchColumn());
        self::assertSame('Existing', $target->query('SELECT label FROM parents WHERE id = 100')->fetchColumn());
        self::assertSame(3, $migrator->run($plan)->totals()['alreadyMapped']);
        self::assertSame(2, $source->query('SELECT COUNT(*) FROM old_parents')->fetchColumn());
        self::assertSame([], $target->query('PRAGMA foreign_key_check')->fetchAll());
    }
    public function testDurableMappingSurvivesReopeningDatabase(): void
    {
        $file = $this->temporaryDatabase();
        $db = $this->db($file); $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
        [$writer, $maps] = $this->adapters($db);
        self::assertSame(1, (new Migrator($this->source(), $writer, $maps))->run($this->plan())->totals()['inserted']);
        unset($writer, $maps, $db);
        $db = $this->db($file); [$writer, $maps] = $this->adapters($db);
        self::assertSame(1, (new Migrator($this->source(), $writer, $maps))->run($this->plan())->totals()['alreadyMapped']);
        self::assertSame(1, $maps->get('sqlite-v1', 'items', SourceKey::fromValues([5]))?->targetId);
        self::assertSame(1, $db->query('SELECT COUNT(*) FROM items')->fetchColumn());
    }
    public function testTargetCommitMapFailureThenReopenRecoversSameId(): void
    {
        $file = $this->temporaryDatabase();
        $db = $this->db($file); $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
        [$writer, $maps] = $this->adapters($db);
        $failure = $this->failure(fn() => (new Migrator($this->source(), $writer, new FaultIdMapStore($maps)))->run($this->plan()));
        self::assertTrue($failure->targetMayHaveCommitted); self::assertSame('map_save', $failure->stage);
        self::assertSame(1, $db->query('SELECT COUNT(*) FROM items')->fetchColumn());
        self::assertNull($maps->get('sqlite-v1', 'items', SourceKey::fromValues([5])));
        unset($failure, $writer, $maps, $db);
        $db = $this->db($file); [$writer, $maps] = $this->adapters($db);
        self::assertSame(1, (new Migrator($this->source(), $writer, $maps))->run($this->plan())->totals()['recovered']);
        self::assertSame(1, $db->query('SELECT COUNT(*) FROM items')->fetchColumn());
        self::assertSame(1, $maps->get('sqlite-v1', 'items', SourceKey::fromValues([5]))?->targetId);
    }
    public function testExceptionAfterRealTargetCommitIsRecoverable(): void
    {
        $db = $this->db(); $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT)');
        [$writer, $maps] = $this->adapters($db);
        $migrator = new Migrator($this->source(), new FaultTargetWriter($writer, true), $maps);
        $failure = $this->failure(fn() => $migrator->run($this->plan()));
        self::assertSame('target_write', $failure->stage);
        self::assertSame(1, $db->query('SELECT COUNT(*) FROM items')->fetchColumn());
        self::assertSame(1, $migrator->run($this->plan())->totals()['recovered']);
        self::assertSame(1, $db->query('SELECT COUNT(*) FROM items')->fetchColumn());
    }
    public function testRealReceiptInsertFailureRollsBackTargetRowAndCanResume(): void
    {
        $db = $this->db(); $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT)');
        [$writer, $maps] = $this->adapters($db);
        $db->exec("CREATE TRIGGER injected_receipt_failure BEFORE INSERT ON _relational_migrator_receipts BEGIN SELECT RAISE(ABORT, 'Injected receipt failure'); END");
        $failure = $this->failure(fn() => (new Migrator($this->source(), $writer, $maps))->run($this->plan()));
        self::assertInstanceOf(\PDOException::class, $failure->getPrevious());
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM items')->fetchColumn());
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM _relational_migrator_receipts')->fetchColumn());
        self::assertFalse($db->inTransaction());
        $db->exec('DROP TRIGGER injected_receipt_failure');
        self::assertSame(1, (new Migrator($this->source(), $writer, $maps))->run($this->plan())->totals()['inserted']);
    }
    public function testConstraintFailureKeepsEarlierRowsAndResumeDoesNotDuplicate(): void
    {
        $db = $this->db(); $db->exec("CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT NOT NULL CHECK (label <> 'bad')); INSERT INTO items VALUES (20, 'preexisting')");
        [$writer, $maps] = $this->adapters($db);
        $badSource = $this->source([['old_id' => 1, 'label' => 'good'], ['old_id' => 2, 'label' => 'bad']]);
        $failure = $this->failure(fn() => (new Migrator($badSource, $writer, $maps))->run($this->plan()));
        self::assertSame(1, $failure->report->totals()['inserted']); self::assertSame(1, $failure->report->totals()['failed']);
        self::assertSame(2, $db->query('SELECT COUNT(*) FROM items')->fetchColumn());
        $report = (new Migrator($this->source([['old_id' => 1, 'label' => 'good'], ['old_id' => 2, 'label' => 'fixed']]), $writer, $maps))->run($this->plan());
        self::assertSame(1, $report->totals()['alreadyMapped']); self::assertSame(1, $report->totals()['inserted']);
        self::assertSame(3, $db->query('SELECT COUNT(*) FROM items')->fetchColumn());
        self::assertSame('preexisting', $db->query('SELECT label FROM items WHERE id = 20')->fetchColumn());
    }
    public function testOuterTransactionIsRejectedWithoutCommittingIt(): void
    {
        $db = $this->db(); $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT)'); [$writer, $maps] = $this->adapters($db);
        $db->beginTransaction();
        $failure = $this->failure(fn() => (new Migrator($this->source(), $writer, $maps))->run($this->plan()));
        self::assertInstanceOf(ConfigurationException::class, $failure->getPrevious()); self::assertTrue($db->inTransaction());
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM items')->fetchColumn()); $db->rollBack();
    }
    public function testMappingPutIsImmutableAndIdTypeIsPreserved(): void
    {
        $db = $this->db(); $maps = new SqliteIdMapStore($db); $maps->initialize();
        $key = SourceKey::fromValues(['t', 8]); $mapping = new Mapping('generated-id', str_repeat('b', 64));
        $maps->put('a', 'items', $key, $mapping); $maps->put('a', 'items', $key, $mapping);
        self::assertTrue($maps->get('a', 'items', $key)?->equals($mapping)); self::assertNull($maps->get('other', 'items', $key));
        try { $maps->put('a', 'items', $key, new Mapping(9, str_repeat('b', 64))); self::fail('Expected conflict.'); }
        catch (MappingConflict) { self::assertTrue($maps->get('a', 'items', $key)?->equals($mapping)); }
    }
    public function testStringPrimaryKeyGeneratedByDatabaseIsSupported(): void
    {
        $db = $this->db(); $db->exec('CREATE TABLE items (id TEXT PRIMARY KEY DEFAULT (lower(hex(randomblob(16)))), label TEXT)');
        [$writer, $maps] = $this->adapters($db); $migrator = new Migrator($this->source(), $writer, $maps);
        $migrator->run($this->plan());
        self::assertIsString($maps->get('sqlite-v1', 'items', SourceKey::fromValues([5]))?->targetId);
        self::assertSame(1, $migrator->run($this->plan())->totals()['alreadyMapped']);
    }
    public function testNullGeneratedIdCausesRollback(): void
    {
        $db = $this->db(); $db->exec('CREATE TABLE items (id TEXT PRIMARY KEY, label TEXT)'); [$writer, $maps] = $this->adapters($db);
        $failure = $this->failure(fn() => (new Migrator($this->source(), $writer, $maps))->run($this->plan()));
        self::assertInstanceOf(RecordException::class, $failure->getPrevious()); self::assertSame(0, $db->query('SELECT COUNT(*) FROM items')->fetchColumn());
    }
    public function testDefaultOnlyTargetRowIsSupported(): void
    {
        $db = $this->db(); $db->exec("CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT DEFAULT 'default')"); [$writer, $maps] = $this->adapters($db);
        $plan = new MigrationPlan('default-v1', new Entity('items', ['old_id'], []));
        self::assertSame(1, (new Migrator($this->source(), $writer, $maps))->run($plan)->totals()['inserted']);
        self::assertSame('default', $db->query('SELECT label FROM items')->fetchColumn());
    }
    public function testBoundValuesDoNotBecomeSql(): void
    {
        $db = $this->db(); $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT)'); [$writer, $maps] = $this->adapters($db);
        $value = "'); DROP TABLE items; --";
        (new Migrator($this->source([['old_id' => "key'quoted", 'label' => $value]]), $writer, $maps))->run($this->plan());
        self::assertSame($value, $db->query('SELECT label FROM items')->fetchColumn());
    }
    public static function invalidTables(): iterable
    {
        yield 'sql characters' => ['items;drop'];
        yield 'reserved receipt table' => ['_relational_migrator_receipts'];
        yield 'reserved map table case insensitive' => ['_RELATIONAL_MIGRATOR_ID_MAP'];
    }
    #[DataProvider('invalidTables')]
    public function testUnsafeOrReservedTableNamesAreRejected(string $name): void
    {
        $this->expectException(ConfigurationException::class); new SqliteTable($name);
    }
    public function testCompositeTargetPrimaryKeyIsRejected(): void
    {
        $db = $this->db(); $db->exec('CREATE TABLE items (id INTEGER, other INTEGER, label TEXT, PRIMARY KEY (id, other))'); [$writer, $maps] = $this->adapters($db);
        $failure = $this->failure(fn() => (new Migrator($this->source(), $writer, $maps))->run($this->plan()));
        self::assertInstanceOf(ConfigurationException::class, $failure->getPrevious()); self::assertSame(0, $db->query('SELECT COUNT(*) FROM items')->fetchColumn());
    }
    public function testExplicitIdFieldIsRejectedIgnoringCase(): void
    {
        $db = $this->db(); $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT)'); [$writer, $maps] = $this->adapters($db);
        $plan = new MigrationPlan('id', new Entity('items', ['old_id'], ['ID' => Field::value(80)]));
        $failure = $this->failure(fn() => (new Migrator($this->source(), $writer, $maps))->run($plan));
        self::assertInstanceOf(ConfigurationException::class, $failure->getPrevious());
    }
    public function testCaseInsensitiveDuplicateColumnsAreRejected(): void
    {
        $db = $this->db(); $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT)'); [$writer, $maps] = $this->adapters($db);
        $plan = new MigrationPlan('columns', new Entity('items', ['old_id'], ['label' => Field::value('one'), 'LABEL' => Field::value('two')]));
        $failure = $this->failure(fn() => (new Migrator($this->source(), $writer, $maps))->run($plan));
        self::assertInstanceOf(ConfigurationException::class, $failure->getPrevious());
    }
    public function testReceiptReplayRejectsChangedFieldsWithoutNewMap(): void
    {
        $db = $this->db(); $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT)'); [$writer, $maps] = $this->adapters($db);
        $entity = $this->plan()->orderedEntities()[0]; $key = SourceKey::fromValues([1]);
        $writer->write('receipt', $entity, $key, ['label' => 'first']);
        $this->expectException(MappingConflict::class); $writer->write('receipt', $entity, $key, ['label' => 'second']);
    }
    public function testReceiptReplayRejectsChangedDestination(): void
    {
        $db = $this->db(); $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT); CREATE TABLE other (id INTEGER PRIMARY KEY, label TEXT)'); [$writer] = $this->adapters($db);
        $entity = $this->plan()->orderedEntities()[0]; $key = SourceKey::fromValues([1]);
        $writer->write('receipt', $entity, $key, ['label' => 'first']);
        $changed = new SqliteTargetWriter($db, ['items' => new SqliteTable('other')]);
        $this->expectException(MappingConflict::class); $changed->write('receipt', $entity, $key, ['label' => 'first']);
    }
    public function testMissingTargetBehindReceiptRequiresManualReconciliation(): void
    {
        $db = $this->db(); $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT)'); [$writer] = $this->adapters($db);
        $entity = $this->plan()->orderedEntities()[0]; $key = SourceKey::fromValues([1]);
        $writer->write('receipt', $entity, $key, ['label' => 'first']);
        // Simulates an external operator deleting target data. The library never deletes it.
        $db->exec('DELETE FROM items');
        $this->expectException(MappingConflict::class); $writer->write('receipt', $entity, $key, ['label' => 'first']);
    }
    public function testHelpersMustBeInitializedExplicitly(): void
    {
        $db = $this->db(); $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT)');
        $failure = $this->failure(fn() => (new Migrator($this->source(), new SqliteTargetWriter($db, ['items' => new SqliteTable('items')]), new SqliteIdMapStore($db)))->run($this->plan()));
        self::assertSame('map_lookup', $failure->stage); self::assertFalse($failure->targetMayHaveCommitted);
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM items')->fetchColumn());
    }
    public function testBusyDatabaseFailureCanBeRetriedAfterLockIsReleased(): void
    {
        $file = $this->temporaryDatabase(); $db = $this->db($file);
        $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT); CREATE TABLE lock_marker (id INTEGER)');
        [$writer, $maps] = $this->adapters($db); $blocker = $this->db($file);
        $blocker->beginTransaction(); $blocker->exec('INSERT INTO lock_marker VALUES (1)');
        $failure = $this->failure(fn() => (new Migrator($this->source(), $writer, $maps))->run($this->plan()));
        self::assertInstanceOf(\PDOException::class, $failure->getPrevious()); self::assertFalse($db->inTransaction());
        $blocker->rollBack();
        self::assertSame(1, (new Migrator($this->source(), $writer, $maps))->run($this->plan())->totals()['inserted']);
        self::assertSame(1, $db->query('SELECT COUNT(*) FROM items')->fetchColumn());
    }
    public function testFloatBindingDoesNotUseLowPhpDisplayPrecision(): void
    {
        $db = $this->db(); $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label REAL)'); [$writer, $maps] = $this->adapters($db);
        $previous = ini_set('precision', '3');
        try {
            $value = 1.234567891234567;
            (new Migrator($this->source([['old_id' => 1, 'label' => $value]]), $writer, $maps))->run($this->plan());
            self::assertSame($value, $db->query('SELECT label FROM items')->fetchColumn());
        } finally { ini_set('precision', $previous); }
    }
    public function testTargetWithoutTransactionJournalIsRejected(): void
    {
        $db = $this->db(); $db->exec('PRAGMA journal_mode = OFF');
        $this->expectException(ConfigurationException::class); new SqliteTargetWriter($db, []);
    }
    public function testMapStoreWithoutTransactionJournalIsRejected(): void
    {
        $db = $this->db(); $db->exec('PRAGMA journal_mode = OFF');
        $this->expectException(ConfigurationException::class); new SqliteIdMapStore($db);
    }
    public function testStringifiedPdoMetadataAndIdsAreSupported(): void
    {
        $db = $this->db(); $db->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT)'); [$writer, $maps] = $this->adapters($db);
        $migrator = new Migrator($this->source(), $writer, $maps); $migrator->run($this->plan());
        self::assertSame('1', $maps->get('sqlite-v1', 'items', SourceKey::fromValues([5]))?->targetId);
        self::assertSame(1, $migrator->run($this->plan())->totals()['alreadyMapped']);
    }
    public function testNonExceptionPdoModeIsRejected(): void
    {
        $db = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]);
        $this->expectException(ConfigurationException::class); new SqliteIdMapStore($db);
    }
}
