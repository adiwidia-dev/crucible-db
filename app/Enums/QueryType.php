<?php

namespace App\Enums;

enum QueryType: string
{
    case Read = 'read';
    case Write = 'write';
}
