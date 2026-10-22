<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator\Tests;
use Lfcheng\RelationalMigrator\{Mapping, SourceKey, WriteResult};
use Lfcheng\RelationalMigrator\Exception\RecordException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class SourceKeyTest extends TestCase
{
    public function testKeysPreserveTypesOrderingAndDelimiters(): void
    {
        self::assertNotSame(SourceKey::fromValues([7])->encode(), SourceKey::fromValues(['7'])->encode());
        self::assertNotSame(SourceKey::fromValues(['a', 'b'])->encode(), SourceKey::fromValues(['b', 'a'])->encode());
        self::assertNotSame(SourceKey::fromValues(['a:b', 'c'])->encode(), SourceKey::fromValues(['a', 'b:c'])->encode());
        self::assertSame('[0,""]', SourceKey::fromValues([0, ''])->encode());
        self::assertSame('["t",2]', SourceKey::fromRow(['tenant', 'id'], ['id' => 2, 'tenant' => 't'])->encode());
    }
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [[]];
        yield 'null' => [[null]];
        yield 'boolean' => [[true]];
        yield 'float' => [[1.5]];
        yield 'array' => [[[]]];
        yield 'object' => [[new \stdClass()]];
        yield 'associative' => [['id' => 1]];
        yield 'invalid utf8' => [["\xB1\x31"]];
    }
    #[DataProvider('invalidKeys')]
    public function testRejectsInvalidKeyComponents(array $values): void
    {
        $this->expectException(RecordException::class);
        SourceKey::fromValues($values);
    }
    public function testMissingKeyColumnFails(): void
    {
        $this->expectException(RecordException::class);
        SourceKey::fromRow(['id'], []);
    }
    public function testInvalidTargetIdFails(): void
    {
        $this->expectException(RecordException::class);
        new WriteResult('', true);
    }
    public function testInvalidFingerprintFails(): void
    {
        $this->expectException(RecordException::class);
        new Mapping(1, 'not-a-digest');
    }
}
