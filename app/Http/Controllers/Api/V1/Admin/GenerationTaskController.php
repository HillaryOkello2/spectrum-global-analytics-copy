<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\GenerationTaskDetailResource;
use App\Http\Resources\GenerationTaskResource;
use App\Models\GenerationTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Admin Portal
 *
 * Task Board (FR-44, §14.9): generated products by workflow status.
 * The board list is lightweight; open a single task to review the full article.
 */
class GenerationTaskController extends Controller
{
    /**
     * Eager-load set for the full proofreading view.
     *
     * @var array<int, string>
     */
    public const DETAIL_RELATIONS = [
        'product.component',
        'topic.component',
        'proofreader',
        'redactor',
        'llmProvider',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $tasks = GenerationTask::query()
            ->with(['topic.component', 'product', 'proofreader', 'redactor', 'llmProvider'])
            ->filter($request->only(['status']))
            ->latest('queued_at')
            ->paginate(30);

        return GenerationTaskResource::collection($tasks);
    }

    /**
     * Read-only full task detail for review — the complete product body, the
     * originating topic (prompt + QA prompt), and the QA result. Does NOT change
     * the task status (unlike open), so proofreaders can read before claiming it.
     */
    public function show(GenerationTask $task): GenerationTaskDetailResource
    {
        return new GenerationTaskDetailResource($task->load(self::DETAIL_RELATIONS));
    }
}
