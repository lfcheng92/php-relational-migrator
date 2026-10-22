# Relational Migrator

[English](README.en.md)

框架无关的 PHP 关联**数据**迁移包：按依赖顺序读取实体、转换字段、写入目标，持久化旧键与新 ID 的映射，再解析跨实体外键。它不管理数据库 schema 版本。

当前为本地首版，尚未创建远程仓库、发布 Packagist 或授予开源许可。包名预留为 `lfcheng92/relational-migrator`。来源与许可边界见 [PROVENANCE.md](PROVENANCE.md)、[LICENSE](LICENSE) 和 [THIRD_PARTY.md](THIRD_PARTY.md)；重新实现本身不自动解决权属问题。

## 环境与本地安装

核心要求 PHP `^8.2`，无运行时 Composer 依赖。SQLite 适配器另需 `ext-pdo`、`ext-pdo_sqlite` 与 SQLite 3.35+；集成验证工具另需 `ext-zip`、`proc_open` 和 Composer 2。开发依赖使用 PHPUnit 与 PHPStan，版本锁定在 `composer.lock`。

在一个与包目录相邻的消费者项目中使用 Composer path 仓库（目前不能从 Packagist 安装）：

```json
{
  "repositories": [{
    "type": "path",
    "url": "../php-relational-migrator",
    "options": {
      "symlink": false,
      "versions": {"lfcheng92/relational-migrator": "dev-main"}
    }
  }],
  "require": {"lfcheng92/relational-migrator": "dev-main"}
}
```

执行 `composer install --no-plugins --no-scripts`，消费者通过 `vendor/autoload.php` 加载。`symlink: false` 用于验证实际复制安装。

## 快速使用

```php
<?php
declare(strict_types=1);
require 'vendor/autoload.php';

use Lfcheng92\RelationalMigrator\{Entity, Field, MigrationPlan, Migrator, Reference};
use Lfcheng92\RelationalMigrator\Adapter\Memory\{
    ArraySourceReader, InMemoryIdMapStore, InMemoryTargetWriter
};

$plan = new MigrationPlan('snapshot-demo-v1',
    new Entity('posts', ['region', 'old_id'],
        ['title' => Field::from('title')],
        ['author_id' => new Reference('authors', ['old_author_id'])]),
    new Entity('authors', ['old_id'],
        ['name' => Field::from('name', static function (mixed $value): string {
            if (!is_string($value)) {
                throw new InvalidArgumentException('Expected string.');
            }
            return trim($value);
        })]),
);
$source = new ArraySourceReader([
    'authors' => [['old_id' => 40, 'name' => ' Ada ']],
    'posts' => [['region' => 'demo', 'old_id' => 91,
        'old_author_id' => 40, 'title' => 'Synthetic data']],
]);
$target = new InMemoryTargetWriter();
$maps = new InMemoryIdMapStore();
$migrator = new Migrator($source, $target, $maps);
$report = $migrator->run($plan); // authors -> posts，2 条 inserted
$repeat = $migrator->run($plan); // 2 条 alreadyMapped
```

内存适配器用于测试和短生命周期演示，进程退出后不保留数据。可直接运行的完整示例见 [examples/memory.php](examples/memory.php) 和 [examples/sqlite.php](examples/sqlite.php)。SQLite 示例使用两套独立的内存数据库，真实开启外键校验，保留预先存在的合成目标记录，并验证第二次执行不会重复插入。

## 声明与 API

| API | 语义 |
| --- | --- |
| `Entity(name, sourceKey, fields, references = [], dependsOn = [])` | 声明实体；源键是有序字段列表；字段和外键映射的目标列不可重名。 |
| `Field::from(source, transform = null)` | 读取源字段。可选回调接收 `(mixed $value, array $row)`；源列不存在即失败，列存在且值为 null 则保留。 |
| `Field::value(value)` | 固定的标量或 null 值，不接受数组、对象或资源。 |
| `Reference(entity, sourceFields, missing = Fail)` | 用子记录的源列构成父实体旧键，再取父实体的新 ID。复合键的字段顺序和数量必须对应父 `sourceKey`。 |
| `MigrationPlan(scope, Entity ...entities)` | 构造时校验全部依赖并生成稳定拓扑顺序；同时可选实体按原声明顺序优先。未知依赖、环、自引用和键数量不匹配立即拒绝，不读取或写入数据。 |
| `Migrator(source, target, maps)->run(plan)` | 逐实体、逐记录执行；遇错即停，返回 `MigrationReport` 或抛 `MigrationFailed`。 |
| `SourceKey::fromValues([...])` / `fromRow(fields, row)` | 简单键和复合键统一表示；组件仅允许 int / UTF-8 string，`7` 与 `"7"` 不同，0 和空字符串有效。 |
| `IdMapStore::get(scope, entity, key)` / `put(..., Mapping)` | 映射按 scope 和实体隔离；包含新 ID 和输出字段 SHA-256 指纹。相同 put 可重复，不同 ID 或指纹不可覆盖。 |

字段、实体、表及列名使用 `[A-Za-z_][A-Za-z0-9_]{0,127}`。scope 使用 1–128 位字母、数字、下划线、点、冒号、连字符，首字符为字母、数字或下划线；请使用不含个人信息的稳定名称。目标 ID 为整数或非空 UTF-8 字符串。输出字段允许 null、布尔、整数、有限浮点数、UTF-8 字符串；二进制和结构化值须由调用方显式转换为合适的字符串。

### 缺失引用

`MissingReferencePolicy` 有三种显式策略：

- `Fail`（默认）：父映射不存在或任一复合键组件为 null 时失败。
- `SetNull`：将该目标外键设为 null；目标 schema 必须允许 null。
- `SkipRow`：跳过整个子记录，不调用目标写入和该记录的映射保存。

缺少源列属于结构错误，任何策略下都失败。非 null 键组件仍须符合键类型要求。父行被跳过后，其子引用也按各自策略处理。不会查询目标业务表来猜测关联，不自动创建占位父记录。

## 失败窗口与重复执行契约

每条记录依次进行：源键提取 → 字段转换与外键解析 → 输出指纹检查 → 查询已有映射 → 目标幂等写入 → 保存映射。已经映射的记录也重新计算输出指纹；输出发生变化即拒绝，而非更新目标。浮点指纹使用 IEEE-754 位表示，不依赖 PHP 的 serialize_precision。

`TargetWriter::write(scope, entity, key, fields)` 的必要契约是：**目标行和该次操作的幂等写入凭据必须在目标端原子持久化**。相同 `(scope, entity, key)`、相同字段重放时必须返回原 ID 和 `inserted=false`；不同字段必须报冲突。一个普通的无保护 INSERT 不满足该接口的语义契约。

SQLite 实现将应用表 INSERT 与 `_relational_migrator_receipts` 写入放在同一 PDO 事务内。在已提交的数据库记录仍完整保留的前提下，即使目标提交后进程退出、返回丢失或 `IdMapStore::put()` 失败，下一次运行也能从凭据恢复原 ID 并补存映射。映射也可能已经保存而异常发生在返回前，此时重跑会计为 `alreadyMapped`。不需要也不声称两个任意数据库之间存在共同事务。

自定义写入器若无法保证上述目标端原子性，就不能承诺安全自动重试。核心没有跨任意数据库 exactly-once 保证；调用方必须审查适配器、事务存储引擎与外部副作用。测试中的人为抛错验证的是明确的失败路径，不代表真实生产宕机、断电或所有驱动均有相同保证。

安全恢复还要求：

- 同一个 scope 对应同一源数据快照、键类型、转换规则、引用定义和目标配置。转换回调应纯净、确定；不要在回调发送消息或写外部服务。
- 不删除或独立修改映射、写入凭据和已迁移目标记录。不共享 scope 给不同来源或不同目标。新 scope 表示一次独立导入，会新增行。
- 单个执行者运行一个 scope；锁冲突会报错，由调用方在释放锁后显式重试。没有内置并发调度、退避或批处理检查点。
- 源读取器须提供稳定快照。SQLite 示例使用独立的源和目标数据库；不要让流式源读取器读取正在写入的同一数据库文件或应用表。需要源快照时由调用方在独立源连接上持有只读事务或使用离线副本。

本版不提供业务全量预演、跨表整体回滚、自动删除、更新/同步已有行或迁移后补偿功能。`orderedEntities()` 只用于查看依赖顺序，不执行读写，也不验证每条源记录。SQLite 的单条事务回滚仅涵盖该条尚未提交的目标行及凭据；前面已提交的记录保留。

## SQLite/PDO 接入

```php
use Lfcheng92\RelationalMigrator\Adapter\Sqlite\{
    SqliteSourceReader, SqliteTargetWriter, SqliteIdMapStore, SqliteTable
};

// $sourcePdo 和 $targetPdo 应为隔离的数据集，均使用 PDO::ERRMODE_EXCEPTION。
$reader = new SqliteSourceReader($sourcePdo, ['authors' => 'old_authors']);
$writer = new SqliteTargetWriter($targetPdo, ['authors' => new SqliteTable('authors', 'id')]);
$maps = new SqliteIdMapStore($targetPdo);
$writer->initialize(); // 只创建本包写入凭据表
$maps->initialize();   // 只创建本包 ID 映射表
$report = (new Migrator($reader, $writer, $maps))->run($authorsPlan);
```

构造器只校验连接与元数据版本，不建表。应用表由调用方事先准备；SQLite 目标必须用单列主键，并由数据库生成非空 int/string ID，不接受在映射字段中显式赋值该 ID 列。`rowid`、`_rowid_`、`oid`（大小写不敏感）如果是隐藏行标识别名也禁止赋值；按 `PRAGMA table_xinfo` 元数据判断，同名真实非主键列仍可正常映射，声明为主键的列始终禁止显式赋值。库使用 `INSERT ... RETURNING` 获取真实 ID。禁止把内部帮助表当成目标；列名忽略大小写也必须唯一。

`SqliteTargetWriter` 自己管理每条事务，拒绝外层已开启的目标事务，不会提交或回滚调用方事务。应用表与凭据表必须位于同一 SQLite 数据库；目标、凭据初始化和每次映射保存都重新读取日志模式，只接受 DELETE / TRUNCATE / PERSIST / MEMORY / WAL；OFF 或未知模式在写入前拒绝，连接初始化后被切换为 OFF 也不能绕过。不要使用会变更返回主键、删除/改写凭据，或产生外部副作用的触发器。目标 schema 的类型亲和性、约束和触发器是调用方职责；库不会复制源列类型或修改目标 schema。

**进程内回滚与崩溃/断电持久性不同。** 日志模式检查及故障注入测试验证的是进程仍在运行时，SQL/映射错误的回滚与重试；本包不更改 `journal_mode`、`synchronous` 或硬件/文件系统设置，也未验证断电恢复。MEMORY 日志仅在 RAM 中，可用于可丢弃的测试数据；文件数据库使用它时，事务中途进程崩溃可能破坏数据库，不能据正常回滚测试宣称崩溃安全。`:memory:` 数据库在连接/进程结束后丢失数据。磁盘持久性还取决于同步设置、操作系统、存储设备和故障模型；例如 `synchronous=OFF` 不提供断电持久性保证。需要可恢复导入时，由应用选择合适的磁盘日志及同步配置，并对自己的环境另行验证。依据见 [SQLite journal_mode](https://www.sqlite.org/pragma.html#pragma_journal_mode) 和 [synchronous](https://www.sqlite.org/pragma.html#pragma_synchronous)。

持久化映射表 `_relational_migrator_id_map` 保存旧键 JSON、新 ID JSON 和指纹。ID 类型与复合键顺序得到保留，映射不可被不同内容覆盖。映射、凭据和源键可能敏感，须限制数据库文件权限与备份访问，详见 [SECURITY.md](SECURITY.md)。

## 报告与异常

报告包含 `completed`、按拓扑顺序排列的 `entities` 与 `totals()`，每个计数器意义如下：

| 计数 | 含义 |
| --- | --- |
| `seen` | 源读取器已交出的记录数，包括跳过和失败的当前记录。 |
| `inserted` | 本次由写入器新增目标行且成功保存映射的记录。 |
| `recovered` | 写入器从既有写入凭据找回 ID 且本次保存映射成功的记录。 |
| `alreadyMapped` | 已有映射且本次输出指纹相同的记录。 |
| `skipped` | 因 `SkipRow` 引用策略跳过的记录。 |
| `failed` | 失败记录或源迭代失败次数。源迭代失败没有新行，所以不一定计入 seen。 |

若目标已提交、映射未保存，失败行不会被计为 inserted；`MigrationFailed::targetMayHaveCommitted` 保守地表示失败行可能已写入，不能据报告推断整个目标数据库的精确行数。可通过 `report` 查看之前完成的进度，通过 `entity` 与 `stage` 定位阶段（`record` / `map_lookup` / `target_write` / `map_save` / `source_read`），通过 `getPrevious()` 获取原始错误。核心报告不含源 ID、行值或原始异常文本；原始驱动异常与堆栈仍可能含敏感内容，不宜直接对外展示。

计划错误用 `ConfigurationException`；记录问题用 `RecordException`，其子类包括 `MappingConflict` 和 `MissingReferenceException`。运行时错误统一包装成 `MigrationFailed`，保留前述原因及部分报告。

## 本地验证

```sh
composer install --no-interaction --prefer-dist --no-plugins --no-scripts
composer validate --strict
composer check
php examples/memory.php
php examples/sqlite.php
php tools/consumer-check.php
```

`composer check` 执行语法检查、PHPStan max 和 PHPUnit。独立消费者工具会：生成并扫描源码 zip → 提取为无 vendor 的快照 → 关闭网络和 Packagist → 在干净目录 Composer path 复制安装且不装开发依赖 → 删除源快照 → 新 PHP 进程校验复合键、真实 SQLite 外键、目标提交后映射失败的恢复及重复执行 → 清理临时文件。如 Composer 非 PATH 命令，可设置 `COMPOSER_BINARY` 为本机 Composer 可执行文件或 phar 路径。

实际执行记录见 [docs/LOCAL_VALIDATION.md](docs/LOCAL_VALIDATION.md)。CI 覆盖配置为 PHP 8.2 / 8.3 / 8.4 / 8.5；没有远程仓库时尚未运行，不应将其视为已验证矩阵。

## 发布边界

参见 [CHANGELOG.md](CHANGELOG.md) 和 [docs/RELEASE_CHECKLIST.md](docs/RELEASE_CHECKLIST.md)。本地验证、后续私有上传、未来公开发布是三个独立关口；单测通过不会触发公开仓库、开源许可证或 Packagist 发布。

设计所用通用文档：[Composer schema](https://getcomposer.org/doc/04-schema.md)、[PDO 事务](https://www.php.net/manual/en/pdo.transactions.php)、[SQLite 事务](https://www.sqlite.org/lang_transaction.html)。
