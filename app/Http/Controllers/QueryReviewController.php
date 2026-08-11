<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewQueryRequestRequest;
use App\Models\QueryRequest;
use App\Services\QueryRequestWorkflow;
use Illuminate\Http\RedirectResponse;

class QueryReviewController extends Controller
{
    public function store(ReviewQueryRequestRequest $request, QueryRequest $queryRequest, QueryRequestWorkflow $workflow): RedirectResponse
    {
        $workflow->review(
            $queryRequest,
            $request->user(),
            $request->validated('decision'),
            $request->validated('comment'),
        );

        return back();
    }
}
