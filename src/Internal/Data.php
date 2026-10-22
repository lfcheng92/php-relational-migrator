<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator\Internal;
use Lfcheng\RelationalMigrator\Exception\RecordException;
/** @internal */
final class Data
{
    /** @param array<string, mixed> $fields
     * @return array<string, bool|float|int|string|null>
     */
    public static function validate(array $fields): array
    {
        foreach ($fields as $name => $value) {
            Rules::identifier($name);
            if ($value !== null && !is_scalar($value)) { throw new RecordException('Target values must be scalar or null.'); }
            if (is_float($value) && !is_finite($value)) { throw new RecordException('Target floats must be finite.'); }
        }
        return $fields;
    }
    /** @param array<string, bool|float|int|string|null> $fields */
    public static function fingerprint(array $fields): string
    {
        ksort($fields, SORT_STRING);
        $canonical = [];
        foreach ($fields as $name => $value) {
            // Arrays are not legal field values, so this marker cannot collide with a string/scalar.
            // IEEE-754 bytes preserve float identity independently of PHP's serialize_precision.
            $canonical[$name] = is_float($value) ? ['float64' => bin2hex(pack('E', $value))] : $value;
        }
        try {
            return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
        } catch (\JsonException $error) {
            throw new RecordException('Target strings must be valid UTF-8.', 0, $error);
        }
    }
    public static function id(int|string $id): void
    {
        if ($id === '' || (is_string($id) && preg_match('//u', $id) !== 1)) {
            throw new RecordException('Target ID must be an integer or a non-empty UTF-8 string.');
        }
    }
}
