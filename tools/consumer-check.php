<?php
declare(strict_types=1);
// Runs without PHPUnit. Verifies the distributed source, offline mirrored path install,
// and SQLite recovery in an independent PHP consumer process.
$packageRoot = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/rm-consumer-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$composer = getenv('COMPOSER_BINARY') ?: 'composer';
$composerCommand = str_ends_with($composer, '.phar') ? [PHP_BINARY, $composer] : [$composer];
$environment = getenv();
$environment['COMPOSER_HOME'] = $temporary . '/composer-home';
$environment['COMPOSER_CACHE_DIR'] = $temporary . '/composer-cache';
$environment['COMPOSER_AUTH'] = '{}';
$environment['COMPOSER_DISABLE_NETWORK'] = '1';
$environment['COMPOSER_ROOT_VERSION'] = 'dev-main';
function runCommand(array $command, string $cwd, array $environment): string
{
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
    if (!is_resource($process)) { throw new RuntimeException('Unable to start verification command.'); }
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { throw new RuntimeException("Verification command failed:\n" . $stdout . $stderr); }
    return $stdout . $stderr;
}
function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (new DirectoryIterator($path) as $entry) { if (!$entry->isDot()) { removeTree($entry->getPathname()); } }
    rmdir($path);
}
try {
    $dist = $temporary . '/dist'; $snapshot = $temporary . '/snapshot'; $consumer = $temporary . '/consumer';
    mkdir($dist); mkdir($snapshot); mkdir($consumer);
    runCommand([...$composerCommand, 'archive', '--format=zip', '--dir=' . $dist, '--file=relational-migrator', '--no-interaction', '--no-plugins', '--no-scripts'], $packageRoot, $environment);
    $archive = $dist . '/relational-migrator.zip';
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) { throw new RuntimeException('Cannot inspect source archive.'); }
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; ++$i) {
        $name = $zip->getNameIndex($i);
        if (!is_string($name) || str_contains($name, '..') || str_starts_with($name, '/')) { throw new RuntimeException('Unsafe archive path.'); }
        if (preg_match('~(^|/)(vendor|node_modules|build|\.git|\.composer|\.idea|\.vscode|\.DS_Store|\.env[^/]*|auth\.json)(/|$)|\.(log|sqlite|db)(-|$)~i', $name)) {
            throw new RuntimeException('Unwanted runtime/development data in source archive: ' . $name);
        }
        $content = $zip->getFromIndex($i);
        if (is_string($content) && is_file($packageRoot . '/' . $name)
            && !hash_equals(hash_file('sha256', $packageRoot . '/' . $name), hash('sha256', $content))) {
            throw new RuntimeException('Archive differs from the saved source: ' . $name);
        }
        if (is_string($content)) {
            if (str_contains($content, $packageRoot)) { throw new RuntimeException('Development directory leaked into archive: ' . $name); }
            if (preg_match('~/+(?:Users|home)/+[^/\s]+/+~', str_replace(chr(92), '/', $content))) { throw new RuntimeException('Machine-specific home path in archive: ' . $name); }
            if (preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|(?:AKIA|ASIA)[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{50,}/', $content)) {
                throw new RuntimeException('Potential credential detected in source archive: ' . $name);
            }
        }
        $entries[] = $name;
    }
    if (!$zip->extractTo($snapshot)) { throw new RuntimeException('Cannot extract source archive.'); }
    $zip->close();
    $manifest = [
        'name' => 'local/isolated-consumer',
        'require' => ['php' => '^8.2', 'lfcheng92/relational-migrator' => 'dev-main'],
        'repositories' => [
            ['type' => 'path', 'url' => $snapshot, 'options' => ['symlink' => false, 'versions' => ['lfcheng92/relational-migrator' => 'dev-main']]],
            ['packagist.org' => false],
        ],
        'config' => ['allow-plugins' => false],
    ];
    file_put_contents($consumer . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    runCommand([...$composerCommand, 'install', '--no-dev', '--no-interaction', '--no-plugins', '--no-scripts', '--prefer-dist'], $consumer, $environment);
    if (is_link($consumer . '/vendor/lfcheng92/relational-migrator')) { throw new RuntimeException('Path package was symlinked.'); }
    // Remove the repository snapshot before runtime to prove the installed copy is standalone.
    removeTree($snapshot);
    file_put_contents($consumer . '/run.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
use Lfcheng\RelationalMigrator\{Entity, Field, Mapping, MigrationPlan, Migrator, Reference, SourceKey};
use Lfcheng\RelationalMigrator\Adapter\Memory\ArraySourceReader;
use Lfcheng\RelationalMigrator\Adapter\Sqlite\{SqliteIdMapStore, SqliteTable, SqliteTargetWriter};
use Lfcheng\RelationalMigrator\Contract\IdMapStore;
use Lfcheng\RelationalMigrator\Exception\MigrationFailed;
$file = (new ReflectionClass(Migrator::class))->getFileName();
if (!is_string($file) || !str_starts_with($file, __DIR__ . '/vendor/lfcheng92/relational-migrator/')) { throw new RuntimeException('Consumer loaded code outside its vendor directory.'); }
function connection(): PDO { return new PDO('sqlite:' . __DIR__ . '/temporary.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
function writer(PDO $db): SqliteTargetWriter { return new SqliteTargetWriter($db, ['parents' => new SqliteTable('parents'), 'children' => new SqliteTable('children')]); }
$db = connection();
$db->exec('PRAGMA foreign_keys = ON; CREATE TABLE parents (id INTEGER PRIMARY KEY, label TEXT); CREATE TABLE children (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES parents(id), label TEXT);');
$source = new ArraySourceReader(['parents' => [['tenant' => 'a', 'old_id' => 7, 'label' => 'A'], ['tenant' => 'b', 'old_id' => 7, 'label' => 'B']], 'children' => [['old_id' => 9, 'tenant' => 'b', 'parent' => 7, 'label' => 'C']]]);
$plan = new MigrationPlan('independent-v1', new Entity('children', ['old_id'], ['label' => Field::from('label')], ['parent_id' => new Reference('parents', ['tenant', 'parent'])]), new Entity('parents', ['tenant', 'old_id'], ['label' => Field::from('label')]));
$target = writer($db); $target->initialize(); $maps = new SqliteIdMapStore($db); $maps->initialize();
$failingMap = new class($maps) implements IdMapStore {
    public function __construct(private IdMapStore $delegate) {}
    public function get(string $scope, string $entity, SourceKey $key): ?Mapping { return $this->delegate->get($scope, $entity, $key); }
    public function put(string $scope, string $entity, SourceKey $key, Mapping $mapping): void { throw new RuntimeException('Injected consumer map failure.'); }
};
$failed = false;
try { (new Migrator($source, $target, $failingMap))->run($plan); }
catch (MigrationFailed $error) { $failed = $error->stage === 'map_save' && $error->targetMayHaveCommitted; }
if (!$failed || (int) $db->query('SELECT COUNT(*) FROM parents')->fetchColumn() !== 1) { throw new RuntimeException('Consumer did not exercise the committed-write/map-failure window.'); }
unset($error, $failingMap, $target, $maps, $db);
$db = connection(); $db->exec('PRAGMA foreign_keys = ON');
$migrator = new Migrator($source, writer($db), new SqliteIdMapStore($db));
$resumed = $migrator->run($plan); $repeat = $migrator->run($plan);
if ($resumed->totals()['recovered'] !== 1 || $resumed->totals()['inserted'] !== 2 || $repeat->totals()['alreadyMapped'] !== 3
    || (int) $db->query('SELECT COUNT(*) FROM parents')->fetchColumn() !== 2 || (int) $db->query('SELECT parent_id FROM children')->fetchColumn() !== 2
    || $db->query('PRAGMA foreign_key_check')->fetchAll() !== []) { throw new RuntimeException('Independent consumer integration verification failed.'); }
echo "Independent consumer OK: 3 rows, composite keys, FK remapping, recovered=1, repeated=3\n";
PHP);
    echo runCommand([PHP_BINARY, $consumer . '/run.php'], $consumer, $environment);
    echo 'Source archive OK: ', count($entries), ' entries; no vendor/runtime data/credentials/development path', PHP_EOL;
    echo 'Offline path install OK: mirrored copy; source snapshot removed before runtime; no dev dependencies', PHP_EOL;
} finally { removeTree($temporary); }
