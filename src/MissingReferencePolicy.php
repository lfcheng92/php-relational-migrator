<?php
declare(strict_types=1);
namespace Lfcheng\RelationalMigrator;
enum MissingReferencePolicy: string
{
    case Fail = 'fail';
    case SetNull = 'set_null';
    case SkipRow = 'skip_row';
}
