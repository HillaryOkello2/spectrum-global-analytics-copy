<?php

namespace App\Services\Generation;

use App\Exceptions\TopicGenerationFailedException;
use App\Models\Component;
use App\Services\Llm\LlmManager;
use Illuminate\Support\Facades\Log;

/**
 * Asks a component's model for a set of prompt variables as JSON, and validates
 * the reply before anything is persisted.
 *
 * Shared by the two paths that need variables nobody typed: the scheduler
 * commissioning a recurring edition (TopicGenerator) and an editor filing a
 * topic with nothing but a title (TopicVariableFiller).
 */
class VariableRequest
{
    public function __construct(
        private readonly LlmManager $llm,
    ) {}

    /**
     * @param  array<int, string>  $expected  keys the reply must carry
     * @return array<string, string>
     *
     * @throws TopicGenerationFailedException
     */
    public function request(Component $component, string $prompt, array $expected): array
    {
        $raw = $this->llm->for($component->assignedLlmProvider)->generate($prompt)->text;

        $decoded = json_decode($this->stripFences($raw), true);

        if (! is_array($decoded)) {
            Log::warning('Variable request returned non-JSON', [
                'component' => $component->code,
                'response' => str($raw)->limit(500)->value(),
            ]);

            throw TopicGenerationFailedException::notJson($component);
        }

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
     * Cheap to tolerate; expensive to fail a run over.
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
