<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class InitialSetupState
{
    public function isComplete(): bool
    {
        if (! Schema::hasTable('application_settings')) {
            return false;
        }

        $setting = ApplicationSetting::query()
            ->where('key', ApplicationSettings::InitialSetupCompleted)
            ->first();

        return filter_var($setting?->value, FILTER_VALIDATE_BOOL);
    }

    public function canInitialize(): bool
    {
        if ($this->isComplete()) {
            return false;
        }

        return ! Schema::hasTable('users') || ! User::query()->exists();
    }
}
