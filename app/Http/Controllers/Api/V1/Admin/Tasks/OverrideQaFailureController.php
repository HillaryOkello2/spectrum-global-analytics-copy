<?php

namespace App\Http\Controllers\Api\V1\Admin\Tasks;

use App\Enums\TaskStatus;
use App\Exceptions\Domain\InvalidTaskTransitionException;
use App\Http\Controllers\Api\V1\Admin\GenerationTaskController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OverrideQaFailureRequest;
use App\Http\Resources\GenerationTaskDetailResource;
use App\Models\GenerationTask;
use App\Services\Generation\GenerationPipeline;

/**
 * @group Admin Portal
 *
 * Overrule a QA failure and take the document into proofreading anyway.
 *
 * The gate exists because a RED LIGHT document should not reach the vault.
 * This exists because the auditors are models too: the same document has come
 * back GREEN on one run and RED on the next with nothing about it changed, so
 * a verdict a proofreader has read and disagrees with must not be the end of
 * the document. The override names them, demands a reason, and is logged.
 */
class OverrideQaFailureController extends Controller
{
    public function __invoke(
        OverrideQaFailureRequest $request,
        GenerationTask $task,
        GenerationPipeline $pipeline,
    ): GenerationTaskDetailResource {
        // Only a task the gate actually stopped. Anything else is a status
        // change dressed up as an override.
        if ($task->status !== TaskStatus::QaFailed) {
            throw new InvalidTaskTransitionException($task->status, TaskStatus::InProofreading);
        }

        $pipeline->overrideQaFailure($task, $request->user(), $request->validated('reason'));

        return new GenerationTaskDetailResource(
            $task->fresh()->load(GenerationTaskController::DETAIL_RELATIONS),
        );
    }
}
