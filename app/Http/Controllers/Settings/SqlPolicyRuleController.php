<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSqlPolicyRuleRequest;
use App\Models\SqlPolicyRule;
use App\Services\SqlPolicyManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class SqlPolicyRuleController extends Controller
{
    public function update(
        UpdateSqlPolicyRuleRequest $request,
        SqlPolicyRule $sqlPolicyRule,
        SqlPolicyManager $manager,
    ): RedirectResponse {
        $manager->setRuleEnabled(
            $sqlPolicyRule,
            $request->user(),
            $request->boolean('is_enabled'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $request->boolean('is_enabled') ? 'Custom SQL policy enabled.' : 'Custom SQL policy disabled.',
        ]);

        return back();
    }
}
