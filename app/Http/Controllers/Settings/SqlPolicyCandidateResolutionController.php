<?php

namespace App\Http\Controllers\Settings;

use App\Enums\SqlPolicyRuleMatchType;
use App\Enums\SqlPolicyRuleScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResolveSqlPolicyCandidateRequest;
use App\Models\SqlPolicyCandidate;
use App\Services\SqlPolicyManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class SqlPolicyCandidateResolutionController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        ResolveSqlPolicyCandidateRequest $request,
        SqlPolicyCandidate $sqlPolicyCandidate,
        SqlPolicyManager $manager,
    ): RedirectResponse {
        $validated = $request->validated();
        $manager->resolveCandidate(
            $sqlPolicyCandidate,
            $request->user(),
            $validated['action'],
            SqlPolicyRuleMatchType::from($validated['match_type']),
            SqlPolicyRuleScope::from($validated['scope_type']),
            isset($validated['scope_id']) ? (int) $validated['scope_id'] : null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $validated['action'] === 'dismiss'
                ? 'Policy candidate dismissed.'
                : 'Custom SQL policy saved and affected preflight reports refreshed.',
        ]);

        return back();
    }
}
