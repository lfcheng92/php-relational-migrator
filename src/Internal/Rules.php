<?php
declare(strict_types=1);
namespace Lfcheng92\RelationalMigrator\Internal;
use Lfcheng92\RelationalMigrator\Exception\ConfigurationException;
/** @internal */
final class Rules
{
    public static function identifier(string $name): void
    {
        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,127}\z/D', $name)) {
            throw new ConfigurationException('Identifiers must match [A-Za-z_][A-Za-z0-9_]{0,127}.');
        }
    }
    public static function scope(string $scope): void
    {
        if (!preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.:-]{0,127}\z/D', $scope)) {
            throw new ConfigurationException('Scope must be a stable, non-sensitive identifier (1-128 characters).');
        }
    }
    /** @param list<string> $fields */
    public static function keyFields(array $fields): void
    {
        if ($fields === [] || !array_is_list($fields) || count(array_unique($fields)) !== count($fields)) {
            throw new ConfigurationException('Key fields must be a non-empty ordered list without duplicates.');
        }
        foreach ($fields as $field) {
            self::identifier($field);
        }
    }
}
