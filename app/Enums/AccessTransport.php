<?php

namespace App\Enums;

enum AccessTransport: string
{
    case Browser = 'browser';
    case NativeProxy = 'native_proxy';
}
