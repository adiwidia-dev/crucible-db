<?php

namespace App\Enums;

enum ApplicationDatabaseMigrationStatus: string
{
    case Planned = 'planned';
    case Copying = 'copying';
    case Copied = 'copied';
    case Verified = 'verified';
    case ActivationPendingRestart = 'activation_pending_restart';
    case Active = 'active';
    case RollbackPendingRestart = 'rollback_pending_restart';
    case RolledBack = 'rolled_back';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Active, self::RolledBack], true);
    }
}
