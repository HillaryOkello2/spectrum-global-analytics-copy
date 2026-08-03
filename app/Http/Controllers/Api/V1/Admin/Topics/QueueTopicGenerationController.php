<?php

namespace App\Http\Controllers\Api\V1\Admin\Topics;

use App\Http\Controllers\Controller;
use App\Http\Resources\GenerationTaskResource;
use App\Models\Topic;
use App\Services\Generation\GenerationPipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * @group Admin Portal
 *
 * Manually queue product generation for a Topic (FR-46, §14.10).
 */
class QueueTopicGenerationController extends Controller
{
    public function __invoke(Request $request, Topic $topic, GenerationPipeline $pipeline): GenerationTaskResource
    {
        Gate::authorize('manage topics');

        $task = $pipeline->queueTopic($topic);

        activity()->causedBy($request->user())->performedOn($topic)->log('product generation queued');

        return new GenerationTaskResource($task->load('topic'));
    }
}
