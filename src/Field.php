<?php
declare(strict_types=1);
namespace Lfcheng92\RelationalMigrator;
use Lfcheng92\RelationalMigrator\Exception\RecordException;
use Lfcheng92\RelationalMigrator\Internal\Rules;
final class Field
{
    /** @param (\Closure(mixed, array<string,mixed>): mixed)|null $transform */
    private function __construct(
        private readonly ?string $source,
        private readonly ?\Closure $transform,
        private readonly mixed $literal = null,
    ) {}
    /** @param (callable(mixed, array<string,mixed>): mixed)|null $transform */
    public static function from(string $source, ?callable $transform = null): self
    {
        Rules::identifier($source);
        return new self($source, $transform === null ? null : \Closure::fromCallable($transform));
    }
    public static function value(bool|float|int|string|null $value): self
    {
        return new self(null, null, $value);
    }
    /** @param array<string, mixed> $row */
    public function resolve(array $row): mixed
    {
        if ($this->source === null) { return $this->literal; }
        if (!array_key_exists($this->source, $row)) {
            throw new RecordException("Source field '{$this->source}' is absent.");
        }
        $value = $row[$this->source];
        return $this->transform === null ? $value : ($this->transform)($value, $row);
    }
}
