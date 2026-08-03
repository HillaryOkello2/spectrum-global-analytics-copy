<?php

namespace App\Http\Controllers\Api\V1\Admin\Tasks;

use App\Http\Controllers\Api\V1\Admin\GenerationTaskController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubmitProofreadRequest;
use App\Http\Resources\GenerationTaskDetailResource;
use App\Models\GenerationTask;
use App\Services\Generation\GenerationPipeline;

/**
 * @group Admin Portal
 *
 * Proofreading stage 1: submit the corrected abstract and document. The task
 * then moves to `awaiting_redaction` for the separate redaction pass (§18.4).
 */
class SubmitProofreadController extends Controller
{
    public function __invoke(SubmitProofreadRequest $request, GenerationTask $task, GenerationPipeline $pipeline): GenerationTaskDetailResource
    {
        $pipeline->submitProofread(
            $task,
            $request->user(),
            $request->validated('abstract'),
            $request->validated('body'),
        );

        return new GenerationTaskDetailResource(
            $task->fresh()->load(GenerationTaskController::DETAIL_RELATIONS),
        );
    }
}
