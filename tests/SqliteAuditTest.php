<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator\Tests;
use Lfcheng\RelationalMigrator\{Entity, Field, Mapping, MigrationPlan, Migrator, SourceKey};
use Lfcheng\RelationalMigrator\Adapter\Memory\ArraySourceReader;
use Lfcheng\RelationalMigrator\Adapter\Sqlite\{SqliteIdMapStore, SqliteSourceReader, SqliteTable, SqliteTargetWriter};
use Lfcheng\RelationalMigrator\Exception\{ConfigurationException, MigrationFailed, RecordException};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class SqliteAuditTest extends TestCase
{
    private function db(): \PDO { return new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]); }
    private function plan(): MigrationPlan { return new MigrationPlan('audit', new Entity('items', ['old_id'], ['label' => Field::from('label')])); }
    private function source(): ArraySourceReader { return new ArraySourceReader(['items' => [['old_id' => 5, 'label' => 'synthetic']]]); }
    private function adapters(\PDO $db): array
    {
        $db->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, label TEXT)');
        $writer = new SqliteTargetWriter($db, ['items' => new SqliteTable('items')]); $maps = new SqliteIdMapStore($db);
        $writer->initialize(); $maps->initialize(); return [$writer, $maps];
    }
    public function testReusedSilentConnectionCannotCommitWithoutReceipt(): void
    {
        $db = $this->db(); [$writer, $maps] = $this->adapters($db);
        $db->exec("CREATE TRIGGER fail_receipt BEFORE INSERT ON main._relational_migrator_receipts BEGIN SELECT RAISE(ABORT, 'synthetic receipt failure'); END");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        $failure = null;
        try { (new Migrator($this->source(), $writer, $maps))->run($this->plan()); } catch (MigrationFailed $error) { $failure = $error; }
        self::assertNotNull($failure, 'A changed PDO error mode must never turn a failed receipt insert into success.');
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM main.items')->fetchColumn());
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM main._relational_migrator_receipts')->fetchColumn());
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM main._relational_migrator_id_map')->fetchColumn());
    }
    public function testTempReceiptShadowCannotCaptureDurableReceipt(): void
    {
        $db = $this->db(); [$writer, $maps] = $this->adapters($db);
        $db->exec('CREATE TEMP TABLE _relational_migrator_receipts (scope TEXT NOT NULL, entity TEXT NOT NULL, source_key TEXT NOT NULL, target_id TEXT NOT NULL, fingerprint TEXT NOT NULL, destination TEXT NOT NULL, PRIMARY KEY(scope,entity,source_key))');
        self::assertSame(1, (new Migrator($this->source(), $writer, $maps))->run($this->plan())->totals()['inserted']);
        self::assertSame(1, $db->query('SELECT COUNT(*) FROM main._relational_migrator_receipts')->fetchColumn());
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM temp._relational_migrator_receipts')->fetchColumn());
    }
    public function testTempTargetDoesNotReplaceConfiguredMainTable(): void
    {
        $db = $this->db(); [$writer, $maps] = $this->adapters($db);
        $db->exec('CREATE TEMP TABLE items (id INTEGER PRIMARY KEY, label TEXT)');
        (new Migrator($this->source(), $writer, $maps))->run($this->plan());
        self::assertSame(1, $db->query('SELECT COUNT(*) FROM main.items')->fetchColumn());
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM temp.items')->fetchColumn());
    }
    public function testTempOnlyTargetIsRejectedBeforeMutations(): void
    {
        $db = $this->db(); $db->exec('CREATE TEMP TABLE items (id INTEGER PRIMARY KEY, label TEXT)');
        $writer = new SqliteTargetWriter($db, ['items' => new SqliteTable('items')]); $maps = new SqliteIdMapStore($db); $writer->initialize(); $maps->initialize();
        $failure = null;
        try { (new Migrator($this->source(), $writer, $maps))->run($this->plan()); } catch (MigrationFailed $error) { $failure = $error; }
        self::assertNotNull($failure); self::assertInstanceOf(ConfigurationException::class, $failure->getPrevious());
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM temp.items')->fetchColumn());
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM main._relational_migrator_receipts')->fetchColumn());
    }
    public function testTempMapDoesNotCapturePersistentMapping(): void
    {
        $db = $this->db(); $maps = new SqliteIdMapStore($db); $maps->initialize();
        $db->exec('CREATE TEMP TABLE _relational_migrator_id_map (scope TEXT NOT NULL, entity TEXT NOT NULL, source_key TEXT NOT NULL, target_id TEXT NOT NULL, fingerprint TEXT NOT NULL, PRIMARY KEY(scope,entity,source_key))');
        $key = SourceKey::fromValues(['t', 5]); $mapping = new Mapping(9, str_repeat('a', 64)); $maps->put('audit', 'items', $key, $mapping);
        self::assertSame(1, $db->query('SELECT COUNT(*) FROM main._relational_migrator_id_map')->fetchColumn());
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM temp._relational_migrator_id_map')->fetchColumn());
        self::assertTrue($mapping->equals($maps->get('audit', 'items', $key)));
    }
    public function testSourceReaderReadsMainInsteadOfSameNamedTempTable(): void
    {
        $db = $this->db(); $db->exec("CREATE TABLE items(old_id INTEGER PRIMARY KEY,label TEXT); INSERT INTO main.items VALUES(1,'main'); CREATE TEMP TABLE items(old_id INTEGER PRIMARY KEY,label TEXT); INSERT INTO temp.items VALUES(1,'temp')");
        $rows = iterator_to_array((new SqliteSourceReader($db, ['items' => 'items']))->read($this->plan()->orderedEntities()[0]));
        self::assertSame([['old_id' => 1, 'label' => 'main']], $rows);
    }
    public function testStatementFailureCannotBeHiddenByModeChangedInsideTrigger(): void
    {
        $db = $this->db(); [$writer, $maps] = $this->adapters($db);
        $reference = \WeakReference::create($db);
        $db->sqliteCreateFunction('switch_error_mode', static function () use ($reference): int {
            $connection = $reference->get();
            if (!$connection instanceof \PDO) { throw new \RuntimeException('Missing test connection.'); }
            $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT); return 1;
        });
        $db->exec("CREATE TRIGGER switch_mode BEFORE INSERT ON main.items BEGIN SELECT switch_error_mode(); END;
            CREATE TRIGGER fail_receipt BEFORE INSERT ON main._relational_migrator_receipts BEGIN SELECT RAISE(ABORT, 'synthetic receipt failure'); END");
        $failure = null;
        try { (new Migrator($this->source(), $writer, $maps))->run($this->plan()); } catch (MigrationFailed $error) { $failure = $error; }
        self::assertNotNull($failure); self::assertInstanceOf(RecordException::class, $failure->getPrevious());
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        self::assertFalse($db->inTransaction());
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM main.items')->fetchColumn());
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM main._relational_migrator_receipts')->fetchColumn());
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM main._relational_migrator_id_map')->fetchColumn());
        $db->exec('DROP TRIGGER switch_mode; DROP TRIGGER fail_receipt');
        self::assertSame(1, (new Migrator($this->source(), $writer, $maps))->run($this->plan())->totals()['inserted']);
    }
    public function testFalseExecuteReturnIsAnExplicitFailure(): void
    {
        $db = $this->db(); $db->exec('CREATE TABLE nonnull (label TEXT NOT NULL)');
        $statement = $db->prepare('INSERT INTO main.nonnull(label) VALUES (?)');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        try { \Lfcheng\RelationalMigrator\Adapter\Sqlite\Sqlite::execute($statement, [null]); self::fail('Expected a failed statement return to throw.'); }
        catch (RecordException) { self::assertSame(0, $db->query('SELECT COUNT(*) FROM main.nonnull')->fetchColumn()); }
    }

    public static function targetTriggerFailures(): iterable
    {
        yield 'ignored target insert' => ["BEFORE INSERT ON main.items BEGIN SELECT RAISE(IGNORE); END"];
        yield 'deleted target row' => ["AFTER INSERT ON main.items BEGIN DELETE FROM items WHERE id = NEW.id; END"];
        yield 'ignored receipt insert' => ["BEFORE INSERT ON main._relational_migrator_receipts BEGIN SELECT RAISE(IGNORE); END"];
        yield 'deleted receipt' => ["AFTER INSERT ON main._relational_migrator_receipts BEGIN DELETE FROM _relational_migrator_receipts; END"];
        yield 'changed receipt ID' => ["AFTER INSERT ON main._relational_migrator_receipts BEGIN UPDATE _relational_migrator_receipts SET target_id = '999'; END"];
        yield 'changed receipt digest' => ["AFTER INSERT ON main._relational_migrator_receipts BEGIN UPDATE _relational_migrator_receipts SET fingerprint = 'tampered'; END"];
        yield 'changed receipt destination' => ["AFTER INSERT ON main._relational_migrator_receipts BEGIN UPDATE _relational_migrator_receipts SET destination = 'elsewhere:id'; END"];
        yield 'receipt trigger deletes target' => ["AFTER INSERT ON main._relational_migrator_receipts BEGIN DELETE FROM items; END"];
    }
    #[DataProvider('targetTriggerFailures')]
    public function testTargetAndReceiptPostconditionsAreVerifiedBeforeCommit(string $trigger): void
    {
        $db = $this->db(); [$writer,$maps] = $this->adapters($db);
        $db->exec("INSERT INTO main.items(id,label) VALUES (100,'keep'); CREATE TRIGGER inject_target_failure " . $trigger);
        $failure = null;
        try { (new Migrator($this->source(),$writer,$maps))->run($this->plan()); } catch (MigrationFailed $error) { $failure = $error; }
        self::assertNotNull($failure, 'A trigger must not produce a successful result with a missing row or invalid receipt.');
        self::assertSame('target_write',$failure->stage);
        self::assertSame([['id' => 100,'label' => 'keep']],$db->query('SELECT id,label FROM main.items')->fetchAll(\PDO::FETCH_ASSOC));
        self::assertSame(0,$db->query('SELECT COUNT(*) FROM main._relational_migrator_receipts')->fetchColumn());
        self::assertSame(0,$db->query('SELECT COUNT(*) FROM main._relational_migrator_id_map')->fetchColumn());
        self::assertFalse($db->inTransaction());
        $db->exec('DROP TRIGGER inject_target_failure');
        self::assertSame(1,(new Migrator($this->source(),$writer,$maps))->run($this->plan())->totals()['inserted']);
        self::assertSame(2,$db->query('SELECT COUNT(*) FROM main.items')->fetchColumn());
    }
    public static function mapTriggerFailures(): iterable
    {
        yield 'ignored map insert' => ["BEFORE INSERT ON main._relational_migrator_id_map BEGIN SELECT RAISE(IGNORE); END"];
        yield 'deleted map' => ["AFTER INSERT ON main._relational_migrator_id_map BEGIN DELETE FROM _relational_migrator_id_map; END"];
        yield 'changed mapped ID' => ["AFTER INSERT ON main._relational_migrator_id_map BEGIN UPDATE _relational_migrator_id_map SET target_id = '999'; END"];
        yield 'changed mapped digest' => ["AFTER INSERT ON main._relational_migrator_id_map BEGIN UPDATE _relational_migrator_id_map SET fingerprint = 'tampered'; END"];
    }
    #[DataProvider('mapTriggerFailures')]
    public function testFailedMapPostconditionDoesNotLeavePoisonedMapping(string $trigger): void
    {
        $db = $this->db(); [$writer,$maps] = $this->adapters($db); $db->exec('CREATE TRIGGER inject_map_failure ' . $trigger);
        $failure = null;
        try { (new Migrator($this->source(),$writer,$maps))->run($this->plan()); } catch (MigrationFailed $error) { $failure = $error; }
        self::assertNotNull($failure); self::assertSame('map_save',$failure->stage);
        self::assertSame(1,$db->query('SELECT COUNT(*) FROM main.items')->fetchColumn());
        self::assertSame(1,$db->query('SELECT COUNT(*) FROM main._relational_migrator_receipts')->fetchColumn());
        self::assertSame(0,$db->query('SELECT COUNT(*) FROM main._relational_migrator_id_map')->fetchColumn());
        self::assertFalse($db->inTransaction());
        $db->exec('DROP TRIGGER inject_map_failure');
        self::assertSame(1,(new Migrator($this->source(),$writer,$maps))->run($this->plan())->totals()['recovered']);
        self::assertSame(1,$maps->get('audit','items',SourceKey::fromValues([5]))?->targetId);
        self::assertSame(1,$db->query('SELECT COUNT(*) FROM main.items')->fetchColumn());
    }
    public function testMapPutPreservesCallerTransactionAndUnrelatedWork(): void
    {
        $db = $this->db(); $maps = new SqliteIdMapStore($db); $maps->initialize(); $db->exec('CREATE TABLE unrelated(id INTEGER)');
        $db->beginTransaction(); $db->exec('INSERT INTO main.unrelated VALUES (1)');
        $key = SourceKey::fromValues([5]); $original = new Mapping(1,str_repeat('b',64)); $maps->put('audit','items',$key,$original);
        try { $maps->put('audit','items',$key,new Mapping(2,str_repeat('b',64))); self::fail('Expected immutable-map conflict.'); }
        catch (\Lfcheng\RelationalMigrator\Exception\MappingConflict) {}
        self::assertTrue($db->inTransaction()); self::assertSame(1,$db->query('SELECT COUNT(*) FROM main.unrelated')->fetchColumn());
        self::assertTrue($original->equals($maps->get('audit','items',$key)));
        $db->rollBack(); self::assertNull($maps->get('audit','items',$key)); self::assertSame(0,$db->query('SELECT COUNT(*) FROM main.unrelated')->fetchColumn());
    }

}
