<?php

namespace App\Http\Controllers\Api\V1\Admin\Tasks;

use App\Http\Controllers\Api\V1\Admin\GenerationTaskController;
use App\Http\Controllers\Controller;
use App\Http\Resources\GenerationTaskDetailResource;
use App\Models\GenerationTask;
use App\Services\Generation\GenerationPipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * @group Admin Portal
 *
 * Open a task for proofreading — captures proofreader + timestamp (FR-45, §18.4)
 * and returns the full article body for review.
 */
class OpenTaskController extends Controller
{
    public function __invoke(Request $request, GenerationTask $task, GenerationPipeline $pipeline): GenerationTaskDetailResource
    {
        Gate::authorize('proofread products');

        $pipeline->openProofreading($task, $request->user());

        return new GenerationTaskDetailResource(
            $task->fresh()->load(GenerationTaskController::DETAIL_RELATIONS),
        );
    }
}
