<?php

namespace App\Enums;

enum ExecutionStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Cancelling = 'cancelling';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case TimedOut = 'timed_out';

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running, self::Cancelling], true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }
}
