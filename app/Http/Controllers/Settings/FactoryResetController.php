<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmFactoryResetRequest;
use App\Models\User;
use App\Services\FactoryResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class FactoryResetController extends Controller
{
    public function __invoke(ConfirmFactoryResetRequest $request, FactoryResetService $factoryReset): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $factoryReset->reset($actor, $request->ip());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('setup.show');
    }
}
