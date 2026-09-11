<?php

namespace App\Exceptions;

use App\Models\NativeProxyLease;
use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class NativeProxyCredentialsAlreadyCreatedException extends Exception implements ShouldntReport
{
    public function __construct(public readonly NativeProxyLease $lease)
    {
        parent::__construct('Native proxy credentials have already been created for this session.');
    }
}
