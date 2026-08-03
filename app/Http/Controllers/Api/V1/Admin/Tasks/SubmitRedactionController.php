<?php

namespace App\Http\Controllers\Api\V1\Admin\Tasks;

use App\Http\Controllers\Api\V1\Admin\GenerationTaskController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubmitRedactionRequest;
use App\Http\Resources\GenerationTaskDetailResource;
use App\Models\GenerationTask;
use App\Services\Generation\GenerationPipeline;

/**
 * @group Admin Portal
 *
 * Proofreading stage 2: submit the redacted document. This approves the
 * product, which then joins the FIFO release queue — it does not go live here
 * (§18.4, §6).
 */
class SubmitRedactionController extends Controller
{
    public function __invoke(SubmitRedactionRequest $request, GenerationTask $task, GenerationPipeline $pipeline): GenerationTaskDetailResource
    {
        $pipeline->submitRedaction(
            $task,
            $request->user(),
            $request->validated('redacted_body'),
        );

        return new GenerationTaskDetailResource(
            $task->fresh()->load(GenerationTaskController::DETAIL_RELATIONS),
        );
    }
}
