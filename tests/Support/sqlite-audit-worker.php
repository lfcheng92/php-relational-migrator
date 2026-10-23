<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Lfcheng\RelationalMigrator\{Entity, Field, MigrationPlan, Migrator, SourceKey};
use Lfcheng\RelationalMigrator\Adapter\Memory\ArraySourceReader;
use Lfcheng\RelationalMigrator\Adapter\Sqlite\{SqliteIdMapStore, SqliteTable, SqliteTargetWriter};
use Lfcheng\RelationalMigrator\Exception\{MappingConflict, MigrationFailed};
try {
    if (count($argv) !== 5) { throw new RuntimeException('Expected isolated database, mode, scope and synthetic label.'); }
    [, $file, $mode, $scope, $label] = $argv;
    $db = new PDO('sqlite:' . $file, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
    $writer = new SqliteTargetWriter($db, ['items' => new SqliteTable('items')]); $maps = new SqliteIdMapStore($db);
    $entity = new Entity('items', ['tenant', 'old_id'], ['label' => Field::from('label')]);
    if ($mode === 'commit-exit') {
        $writer->write($scope, $entity, SourceKey::fromValues(['synthetic', 7]), ['label' => $label]);
        exit(73); // A real process exit after target+receipt commit, before map persistence.
    }
    if ($mode !== 'race') { throw new RuntimeException('Unknown worker mode.'); }
    fwrite(STDOUT, "READY\n"); fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') { throw new RuntimeException('Missing test barrier.'); }
    $source = new ArraySourceReader(['items' => [['tenant' => 'synthetic', 'old_id' => 7, 'label' => $label]]]);
    try {
        $report = (new Migrator($source, $writer, $maps))->run(new MigrationPlan($scope, $entity));
        $result = ['status' => 'completed', 'totals' => $report->totals()];
    } catch (MigrationFailed $error) {
        $previous = $error->getPrevious();
        $driverCode = $previous instanceof PDOException ? ($previous->errorInfo[1] ?? null) : null;
        if (is_int($driverCode) && in_array($driverCode & 0xff, [5, 6], true)) { $result = ['status' => 'busy']; }
        elseif ($previous instanceof MappingConflict) { $result = ['status' => 'conflict']; }
        else { throw $error; }
    }
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) { fwrite(STDERR, get_class($error) . "\n"); exit(1); }
