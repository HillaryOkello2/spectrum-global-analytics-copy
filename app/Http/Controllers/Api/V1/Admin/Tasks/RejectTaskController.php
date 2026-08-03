<?php

namespace App\Http\Controllers\Api\V1\Admin\Tasks;

use App\Http\Controllers\Api\V1\Admin\GenerationTaskController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectTaskRequest;
use App\Http\Resources\GenerationTaskDetailResource;
use App\Models\GenerationTask;
use App\Services\Generation\GenerationPipeline;

/**
 * @group Admin Portal
 *
 * Reject a proofread product with a note; the task returns to the board (§18.4).
 */
class RejectTaskController extends Controller
{
    public function __invoke(RejectTaskRequest $request, GenerationTask $task, GenerationPipeline $pipeline): GenerationTaskDetailResource
    {
        $pipeline->reject($task, $request->user(), $request->validated('note'));

        return new GenerationTaskDetailResource(
            $task->fresh()->load(GenerationTaskController::DETAIL_RELATIONS),
        );
    }
}
