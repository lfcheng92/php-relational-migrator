<?php
declare(strict_types=1);
namespace Lfcheng92\RelationalMigrator;
use Lfcheng92\RelationalMigrator\Internal\Rules;
final class Reference
{
    /** $sourceFields positions correspond to the referenced entity's sourceKey positions.
     * @param list<string> $sourceFields
     */
    public function __construct(
        public readonly string $entity,
        public readonly array $sourceFields,
        public readonly MissingReferencePolicy $missing = MissingReferencePolicy::Fail,
    ) {
        Rules::identifier($entity);
        Rules::keyFields($sourceFields);
    }
}
