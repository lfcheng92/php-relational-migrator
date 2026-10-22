<?php
declare(strict_types=1);
// Synthetic, in-memory databases only. No DSN, credentials, files, or network required.
require __DIR__ . '/../vendor/autoload.php';
use Lfcheng92\RelationalMigrator\{Entity, Field, MigrationPlan, Migrator, Reference};
use Lfcheng92\RelationalMigrator\Adapter\Sqlite\{SqliteIdMapStore, SqliteSourceReader, SqliteTable, SqliteTargetWriter};

$source = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$target = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$source->exec("CREATE TABLE old_authors (legacy_id INTEGER PRIMARY KEY, name TEXT NOT NULL);
    CREATE TABLE old_posts (region TEXT, legacy_id INTEGER, author_id INTEGER, title TEXT, PRIMARY KEY(region, legacy_id));
    INSERT INTO old_authors VALUES (40, '  Ada  ');
    INSERT INTO old_posts VALUES ('demo', 91, 40, 'Synthetic example');");
$target->exec("PRAGMA foreign_keys = ON;
    CREATE TABLE authors (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
    CREATE TABLE posts (id INTEGER PRIMARY KEY, author_id INTEGER NOT NULL REFERENCES authors(id), title TEXT NOT NULL);
    INSERT INTO authors (id, name) VALUES (10, 'Pre-existing synthetic row');");

$plan = new MigrationPlan('demo-v1',
    // Deliberately declared before its parent; the plan sorts dependencies.
    new Entity('posts', ['region', 'legacy_id'], ['title' => Field::from('title')],
        ['author_id' => new Reference('authors', ['author_id'])]),
    new Entity('authors', ['legacy_id'], ['name' => Field::from('name', static function (mixed $name): string {
        if (!is_string($name)) { throw new InvalidArgumentException('Expected a name string.'); }
        return trim($name);
    })]),
);
$writer = new SqliteTargetWriter($target, ['authors' => new SqliteTable('authors'), 'posts' => new SqliteTable('posts')]);
$maps = new SqliteIdMapStore($target);
$writer->initialize();
$maps->initialize();
$migrator = new Migrator(new SqliteSourceReader($source, ['authors' => 'old_authors', 'posts' => 'old_posts']), $writer, $maps);
$first = $migrator->run($plan);
$repeat = $migrator->run($plan);
if ($first->totals()['inserted'] !== 2 || $repeat->totals()['alreadyMapped'] !== 2
    || (int) $target->query('SELECT COUNT(*) FROM authors')->fetchColumn() !== 2
    || (int) $target->query('SELECT author_id FROM posts')->fetchColumn() !== 11) {
    throw new RuntimeException('SQLite example verification failed.');
}
echo json_encode(['first' => $first, 'repeat' => $repeat], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
