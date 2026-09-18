<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\SystemStatus;
use Inertia\Inertia;
use Inertia\Response;

class SystemStatusController extends Controller
{
    public function show(SystemStatus $systemStatus): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return Inertia::render('settings/admin/system-status', [
            'system_status' => $systemStatus->latest(),
            'application_runtime' => $systemStatus->applicationRuntime(),
            'wayfinder' => $systemStatus->wayfinderStatus(),
        ]);
    }
}
