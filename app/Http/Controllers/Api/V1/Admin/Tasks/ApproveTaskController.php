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
 * Approve a product without a redaction pass (FR-28/29, §18.4). Approval queues
 * it for release; `products:release` is what takes it live.
 */
class ApproveTaskController extends Controller
{
    public function __invoke(Request $request, GenerationTask $task, GenerationPipeline $pipeline): GenerationTaskDetailResource
    {
        Gate::authorize('proofread products');

        $pipeline->approve($task, $request->user());

        return new GenerationTaskDetailResource(
            $task->fresh()->load(GenerationTaskController::DETAIL_RELATIONS),
        );
    }
}
