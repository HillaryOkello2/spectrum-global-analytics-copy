<?php

namespace App\Services\Generation;

use App\Models\Component;
use App\Models\Topic;

/**
 * Fills in the prompt variables an editor did not type.
 *
 * A topic filed from the admin portal carries a title and a component, which is
 * all an editor should have to decide. The client's prompt templates, though,
 * want several slots filled — a byline, a document title, the four themes of a
 * Close-Circuit brief. The title answers whichever slot means "the subject";
 * the component's own model is asked for the rest, once, and the answer is
 * stored on the topic so a retry renders exactly the same document.
 */
class TopicVariableFiller
{
    /**
     * Slots that mean "what this edition is about", best match first.
     */
    private const SUBJECT_KEYS = ['PRIMARY_TOPIC', 'DOCUMENT_TITLE', 'PROJECT_FILE'];

    /**
     * What each known slot is, in the words the model needs to fill it.
     */
    private const GUIDANCE = [
        'BYLINE' => 'a one-line subtitle for the document, no author name',
        'DOCUMENT_TITLE' => 'the formal title of this document',
        'DOCUMENT_SUBTITLE' => 'a one-line subtitle beneath the title',
        'BOOK_VOLUME_TITLE' => 'the title of the volume this study belongs to',
        'PROJECT_FILE' => 'a short codename for this simulation file',
        'TARGET_THREAT_MATRIX' => 'the threat matrix this simulation stresses, in a few words',
    ];

    public function __construct(
        private readonly VariableRequest $variables,
    ) {}

    public function fill(Topic $topic): Topic
    {
        // A topic carrying its own prompt renders no template, so it needs none.
        if ($topic->hasOwnPrompt()) {
            return $topic;
        }

        $component = $topic->component;
        $filled = $topic->variables ?? [];

        $missing = $this->missing($component, $filled);

        if ($missing === []) {
            return $topic;
        }

        // The editor's title is the subject of the edition.
        foreach (self::SUBJECT_KEYS as $key) {
            if (in_array($key, $missing, true)) {
                $filled[$key] = $topic->title;
                break;
            }
        }

        $missing = array_values(array_diff($missing, array_keys($filled)));

        if ($missing !== []) {
            $filled = [...$filled, ...$this->variables->request(
                $component,
                $this->prompt($component, $topic, $missing),
                $missing,
            )];
        }

        $topic->update(['variables' => $filled]);

        return $topic;
    }

    /**
     * Declared slots with no value yet. Anything the component fixes for the
     * whole series is already supplied at render time and is not asked for.
     *
     * @param  array<string, string>  $filled
     * @return array<int, string>
     */
    private function missing(Component $component, array $filled): array
    {
        return array_values(array_diff(
            $component->variables ?? [],
            array_keys($filled),
            array_keys($component->fixed_variables ?? []),
        ));
    }

    /**
     * @param  array<int, string>  $missing
     */
    private function prompt(Component $component, Topic $topic, array $missing): string
    {
        $keys = array_map(
            fn (string $key) => '  "'.$key.'": '.(self::GUIDANCE[$key]
                ?? 'a short value for this slot, in the register of the document'),
            $missing,
        );

        return implode("\n", [
            "You are commissioning one edition of the {$component->name} for Spectrum Global Analytics.",
            'An editor has filed this subject: "'.$topic->title.'"',
            '',
            'Return ONLY a JSON object, no prose and no code fence, in exactly this shape:',
            '{',
            implode(",\n", $keys),
            '}',
            '',
            'Each value is a short plain-text string in the formal, analytical register of the series,',
            'consistent with the subject above. Do not restate the subject verbatim in every field.',
        ]);
    }
}
