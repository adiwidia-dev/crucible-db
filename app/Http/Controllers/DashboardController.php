<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DashboardOverview;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(DashboardOverview $dashboardOverview): Response
    {
        /** @var User $user */
        $user = request()->user();

        return Inertia::render('dashboard', $dashboardOverview->for($user));
    }
}
