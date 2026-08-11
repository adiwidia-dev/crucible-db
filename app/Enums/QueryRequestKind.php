<?php

namespace App\Enums;

enum QueryRequestKind: string
{
    case SingleExecution = 'single_execution';
    case QueryAccess = 'query_access';
}
