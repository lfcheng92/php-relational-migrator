<?php
declare(strict_types=1);
namespace Lfcheng92\RelationalMigrator;
enum MissingReferencePolicy: string
{
    case Fail = 'fail';
    case SetNull = 'set_null';
    case SkipRow = 'skip_row';
}
