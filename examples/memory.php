<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use Lfcheng92\RelationalMigrator\{Entity, Field, MigrationPlan, Migrator};
use Lfcheng92\RelationalMigrator\Adapter\Memory\{ArraySourceReader, InMemoryIdMapStore, InMemoryTargetWriter};
$source = new ArraySourceReader(['items' => [['old_id' => 'demo-1', 'label' => 'Synthetic item']]]);
$writer = new InMemoryTargetWriter();
$maps = new InMemoryIdMapStore();
$plan = new MigrationPlan('example-v1', new Entity('items', ['old_id'], ['label' => Field::from('label')]));
echo json_encode((new Migrator($source, $writer, $maps))->run($plan), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
