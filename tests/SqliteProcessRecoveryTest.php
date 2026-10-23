<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator\Tests;
use Lfcheng\RelationalMigrator\{Entity, Field, Mapping, MigrationPlan, Migrator, SourceKey};
use Lfcheng\RelationalMigrator\Adapter\Memory\ArraySourceReader;
use Lfcheng\RelationalMigrator\Adapter\Sqlite\{SqliteIdMapStore, SqliteTable, SqliteTargetWriter};
use Lfcheng\RelationalMigrator\Exception\{MappingConflict, MigrationFailed};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class SqliteProcessRecoveryTest extends TestCase
{
    private string $directory;
    private string $file;
    private \PDO $db;
    private array $workers = [];
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rm-process-' . bin2hex(random_bytes(8)); mkdir($this->directory, 0700);
        $this->file = $this->directory . '/isolated.sqlite'; $this->db = $this->connect();
        $this->db->exec('CREATE TABLE items(id INTEGER PRIMARY KEY,label TEXT)');
        (new SqliteTargetWriter($this->db, ['items' => new SqliteTable('items')]))->initialize(); (new SqliteIdMapStore($this->db))->initialize();
    }
    private function connect(): \PDO { return new \PDO('sqlite:' . $this->file, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 2]); }
    protected function tearDown(): void
    {
        foreach ($this->workers as [$process, $pipes]) {
            if (is_resource($process)) { proc_terminate($process); }
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_close($process); }
        }
        unset($this->db);
        foreach (glob($this->directory . '/*') ?: [] as $file) { unlink($file); }
        rmdir($this->directory);
    }
    private function plan(): MigrationPlan { return new MigrationPlan('process-audit', new Entity('items', ['tenant','old_id'], ['label' => Field::from('label')])); }
    private function migrate(string $label): \Lfcheng\RelationalMigrator\MigrationReport
    {
        $source = new ArraySourceReader(['items' => [['tenant' => 'synthetic', 'old_id' => 7, 'label' => $label]]]);
        return (new Migrator($source, new SqliteTargetWriter($this->db, ['items' => new SqliteTable('items')]), new SqliteIdMapStore($this->db)))->run($this->plan());
    }
    private function start(string $mode, string $label): array
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/Support/sqlite-audit-worker.php', $this->file, $mode, 'process-audit', $label], [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes);
        self::assertIsResource($process); $worker = [$process, $pipes]; $this->workers[] = $worker; return $worker;
    }
    private function line($stream): string
    {
        $read = [$stream]; $write = null; $except = null;
        if (stream_select($read, $write, $except, 10) !== 1) { throw new \RuntimeException('Worker barrier timed out.'); }
        $line = fgets($stream); if ($line === false) { throw new \RuntimeException('Worker exited without a result.'); } return trim($line);
    }
    public function testRealProcessExitAfterCommitRecoversOriginalId(): void
    {
        [$process,$pipes] = $this->start('commit-exit','stable'); fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(73, proc_close($process), $stderr); $this->workers = [];
        self::assertSame('', $stdout); self::assertSame(1, $this->db->query('SELECT COUNT(*) FROM main.items')->fetchColumn());
        self::assertSame(1, $this->db->query('SELECT COUNT(*) FROM main._relational_migrator_receipts')->fetchColumn());
        self::assertSame(0, $this->db->query('SELECT COUNT(*) FROM main._relational_migrator_id_map')->fetchColumn());
        $original = $this->db->query('SELECT id FROM main.items')->fetchColumn(); unset($this->db); $this->db = $this->connect();
        self::assertSame(1, $this->migrate('stable')->totals()['recovered']);
        self::assertSame($original, (new SqliteIdMapStore($this->db))->get('process-audit','items',SourceKey::fromValues(['synthetic',7]))?->targetId);
        self::assertSame(1, $this->migrate('stable')->totals()['alreadyMapped']);
        self::assertSame(1, $this->db->query('SELECT COUNT(*) FROM main.items')->fetchColumn());
    }
    public static function races(): iterable
    {
        foreach (['delete','wal'] as $mode) {
            yield $mode . ' identical payloads' => [false,$mode];
            yield $mode . ' conflicting payloads' => [true,$mode];
        }
    }
    #[DataProvider('races')]
    public function testTwoIndependentWritersNeverDuplicateSourceKey(bool $conflicting, string $mode): void
    {
        $setting = $this->db->query('PRAGMA main.journal_mode = ' . $mode); self::assertSame($mode,$setting->fetchColumn()); $setting->closeCursor();
        $first = $this->start('race','alpha'); $second = $this->start('race',$conflicting ? 'beta' : 'alpha');
        foreach ([$first,$second] as [, $pipes]) { self::assertSame('READY', $this->line($pipes[1])); }
        foreach ([$first,$second] as [, $pipes]) { fwrite($pipes[0], "GO\n"); fflush($pipes[0]); }
        foreach ([$first,$second] as [$process,$pipes]) {
            $result = json_decode($this->line($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
            self::assertContains($result['status'], $conflicting ? ['completed','busy','conflict'] : ['completed','busy']);
            fclose($pipes[0]); fclose($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]); self::assertSame(0, proc_close($process), $stderr);
        }
        $this->workers = [];
        self::assertLessThanOrEqual(1, $this->db->query('SELECT COUNT(*) FROM main.items')->fetchColumn());
        $winner = $this->db->query('SELECT label FROM main.items')->fetchColumn(); if ($winner === false) { $winner = 'alpha'; }
        $this->migrate($winner);
        self::assertSame(1, $this->db->query('SELECT COUNT(*) FROM main.items')->fetchColumn());
        self::assertSame(1, $this->db->query('SELECT COUNT(*) FROM main._relational_migrator_receipts')->fetchColumn());
        self::assertSame(1, $this->db->query('SELECT COUNT(*) FROM main._relational_migrator_id_map')->fetchColumn());
        if ($conflicting) {
            try { $this->migrate($winner === 'alpha' ? 'beta' : 'alpha'); self::fail('Expected conflict after the winning payload.'); }
            catch (MigrationFailed $error) { self::assertInstanceOf(MappingConflict::class, $error->getPrevious()); }
            self::assertSame($winner, $this->db->query('SELECT label FROM main.items')->fetchColumn());
        }
    }
    public function testTypedCompositeMappingsRemainDistinctAfterReopening(): void
    {
        $keys = [[0,'x'],['0','x'],['tenant',''],['tenant',0],['t:a','b'],['t','a:b'],['synthetic',7],['synthetic','7']];
        $store = new SqliteIdMapStore($this->db);
        foreach ($keys as $i => $values) { $store->put('typed','items',SourceKey::fromValues($values),new Mapping($i + 1,hash('sha256',(string)$i))); }
        unset($store,$this->db); $this->db = $this->connect(); $store = new SqliteIdMapStore($this->db);
        foreach ($keys as $i => $values) {
            self::assertSame($i + 1,$store->get('typed','items',SourceKey::fromValues($values))?->targetId);
            self::assertNull($store->get('other','items',SourceKey::fromValues($values)));
        }
    }
}
