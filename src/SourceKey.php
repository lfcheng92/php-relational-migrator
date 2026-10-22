<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator;
use Lfcheng\RelationalMigrator\Exception\RecordException;
use Lfcheng\RelationalMigrator\Internal\Rules;
final class SourceKey
{
    /** @param non-empty-list<int|string> $values */
    private function __construct(public readonly array $values, private readonly string $encoded) {}
    /** Integer and string identities differ, including 7 and "7".
     * @param list<mixed> $values
     */
    public static function fromValues(array $values): self
    {
        if ($values === [] || !array_is_list($values)) {
            throw new RecordException('A source key must be a non-empty ordered list.');
        }
        foreach ($values as $value) {
            if (!is_int($value) && !is_string($value)) {
                throw new RecordException('Source key components must be integers or strings; null is not a key.');
            }
        }
        try {
            $encoded = json_encode($values, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RecordException('Source key strings must be valid UTF-8.', 0, $error);
        }
        /** @var non-empty-list<int|string> $values */
        return new self($values, $encoded);
    }
    /** @param list<string> $fields
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $fields, array $row): self
    {
        Rules::keyFields($fields);
        $values = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                throw new RecordException("Source key column '$field' is absent.");
            }
            $values[] = $row[$field];
        }
        return self::fromValues($values);
    }
    public function encode(): string { return $this->encoded; }
}
