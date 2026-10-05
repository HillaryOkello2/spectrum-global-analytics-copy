<?php

namespace App\Services\Generation;

use App\Enums\Frequency;
use App\Exceptions\TopicGenerationFailedException;
use App\Models\Component;
use App\Models\Topic;

/**
 * Commissions a topic for a recurring component.
 *
 * The daily brief, weekly highlights and monthly focus all have a fixed title
 * and a static prompt — the only thing that changes edition to edition is the
 * subject. Rather than requiring an admin to file one every morning, the
 * component's `topic_prompt` asks its assigned model to pick the subject, and
 * the reply becomes the topic's `variables`.
 */
class TopicGenerator
{
    public function __construct(
        private readonly VariableRequest $variables,
        private readonly PromptRenderer $renderer,
    ) {}

    /**
     * @throws TopicGenerationFailedException
     */
    public function generate(Component $component): Topic
    {
        if (! $component->canCommissionTopics()) {
            throw TopicGenerationFailedException::notAutomated($component);
        }

        $variables = $this->variables->request(
            $component,
            $component->topic_prompt,
            $component->variables ?? [],
        );

        $topic = new Topic([
            'component_id' => $component->id,
            // Components without a scheduled cadence still get a valid one:
            // the topic recurs monthly unless the client says otherwise.
            'frequency' => $component->generation_frequency ?? Frequency::Monthly,
            'source' => Topic::SOURCE_AUTO,
            'variables' => $variables,
            'is_active' => true,
            // Left null deliberately: an auto topic renders the component's
            // prompt_template against `variables` instead of carrying its own.
            'prompt_text' => null,
            'qa_prompt_text' => null,
            // Placeholder — replaced below once the topic can be rendered.
            'title' => $component->name,
        ]);
        $topic->setRelation('component', $component);

        $topic->title = str($this->renderer->renderTitle($topic))->limit(250)->value();
        $topic->save();

        return $topic;
    }
}
