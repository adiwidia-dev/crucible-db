<?php

namespace App\Enums;

enum PreflightStatus: string
{
    case NotRun = 'not_run';
    case Passed = 'passed';
    case PassedWithWarnings = 'passed_with_warnings';
    case Blocked = 'blocked';
    case Stale = 'stale';
}
