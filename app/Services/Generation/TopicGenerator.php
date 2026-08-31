<?php

namespace App\Services\Generation;

use App\Enums\Frequency;
use App\Exceptions\TopicGenerationFailedException;
use App\Models\Component;
use App\Models\Topic;
use App\Services\Llm\LlmManager;
use Illuminate\Support\Facades\Log;

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
        private readonly LlmManager $llm,
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

        $variables = $this->requestVariables($component);

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

    /**
     * Ask the model for this edition's variables and validate the shape before
     * anything is persisted. A malformed reply fails the task rather than
     * producing a document with an empty subject.
     *
     * @return array<string, string>
     */
    private function requestVariables(Component $component): array
    {
        $raw = $this->llm->for($component->assignedLlmProvider)
            ->generate($component->topic_prompt)
            ->text;

        $decoded = json_decode($this->stripFences($raw), true);

        if (! is_array($decoded)) {
            Log::warning('Topic generation returned non-JSON', [
                'component' => $component->code,
                'response' => str($raw)->limit(500)->value(),
            ]);

            throw TopicGenerationFailedException::notJson($component);
        }

        $expected = $component->variables ?? [];
        $missing = array_diff($expected, array_keys($decoded));

        if ($missing !== []) {
            throw TopicGenerationFailedException::missingKeys($component, array_values($missing));
        }

        // Ignore anything extra the model volunteered, and flatten to strings so
        // a nested object can never reach the prompt as "Array".
        $variables = [];

        foreach ($expected as $key) {
            $value = $decoded[$key];

            if (! is_scalar($value)) {
                throw TopicGenerationFailedException::nonScalar($component, $key);
            }

            $variables[$key] = trim((string) $value);
        }

        return $variables;
    }

    /**
     * Models often wrap JSON in a ```json fence despite being told not to.
     * Cheap to tolerate; expensive to fail a scheduled run over.
     */
    private function stripFences(string $raw): string
    {
        $trimmed = trim($raw);

        if (! str_starts_with($trimmed, '```')) {
            return $trimmed;
        }

        return trim(preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $trimmed));
    }
}
