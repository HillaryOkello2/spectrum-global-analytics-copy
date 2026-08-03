<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTopicRequest;
use App\Http\Resources\TopicResource;
use App\Models\Component;
use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Admin Portal
 *
 * Product Generation Master: topic management (FR-21/22/46, §14.10).
 * Manual generation queuing lives in Topics\QueueTopicGenerationController.
 */
class TopicController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $topics = Topic::query()
            ->with('component')
            ->latest()
            ->paginate(20);

        return TopicResource::collection($topics);
    }

    public function store(StoreTopicRequest $request): TopicResource
    {
        $component = Component::where('public_id', $request->validated('component'))->firstOrFail();

        $topic = Topic::create([
            ...$request->safe()->except('component'),
            'component_id' => $component->id,
        ]);

        activity()->causedBy($request->user())->performedOn($topic)->log('topic created');

        return new TopicResource($topic->load('component'));
    }
}
