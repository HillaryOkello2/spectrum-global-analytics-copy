<?php

namespace App\Services\Generation;

use App\Exceptions\UnresolvedPromptPlaceholderException;
use App\Models\Topic;
use Carbon\CarbonInterface;

/**
 * Interpolates `[PLACEHOLDER]` tokens in the client's prompt templates.
 *
 * The client's prompts are written with bracketed slots — [PRIMARY_TOPIC],
 * [DOCUMENT_REF], [DATE] and so on. A topic supplies the editorial ones, the
 * component supplies any that are constant for the whole series, and this class
 * supplies the two the system owns.
 */
class PromptRenderer
{
    /**
     * Matches a placeholder: uppercase, digits and underscores only, so prose
     * like "[SGA.P1]" (a pillar vector code the prompts legitimately contain)
     * is left alone.
     */
    private const TOKEN = '/\[([A-Z][A-Z0-9_]*)\]/';

    /**
     * Render a template, substituting every known placeholder.
     *
     * @param  array<string, string>  $variables
     *
     * @throws UnresolvedPromptPlaceholderException when a slot has no value —
     *                                              better to fail here than to ship a literal "[PRIMARY_TOPIC]" into
     *                                              a document that goes to a sovereign client.
     */
    public function render(string $template, array $variables): string
    {
        $missing = [];

        $rendered = preg_replace_callback(self::TOKEN, function (array $match) use ($variables, &$missing): string {
            [$token, $key] = $match;

            if (! array_key_exists($key, $variables)) {
                // Not every bracketed word is a slot we own — the templates also
                // print vector codes and section labels. Only flag a token the
                // component declared and nobody filled.
                $missing[] = $key;

                return $token;
            }

            return (string) $variables[$key];
        }, $template);

        if ($missing !== []) {
            throw UnresolvedPromptPlaceholderException::for(array_values(array_unique($missing)));
        }

        return $rendered;
    }

    /**
     * Render the product prompt for one generation run.
     *
     * `$documentRef` is the product's allocated code, which the document prints
     * as its [DOCUMENT_REF] — that is why the two must agree.
     */
    public function renderProductPrompt(Topic $topic, string $documentRef, ?CarbonInterface $at = null): string
    {
        if ($topic->hasOwnPrompt()) {
            return $topic->prompt_text;
        }

        return $this->render(
            $topic->component->prompt_template ?? '',
            $this->variablesFor($topic, $documentRef, $at)
        );
    }

    public function renderQaPrompt(Topic $topic): string
    {
        return $topic->qa_prompt_text ?? $topic->component->qa_prompt_template ?? '';
    }

    /**
     * The product title, from the component's title_template. Falls back to the
     * topic title so a hand-written topic keeps working unchanged.
     */
    public function renderTitle(Topic $topic, ?CarbonInterface $at = null): string
    {
        $template = $topic->component->title_template;

        if (blank($template)) {
            return $topic->title;
        }

        return $this->render($template, $this->variablesFor($topic, '', $at));
    }

    /**
     * Editorial variables from the topic, series constants from the component,
     * and the two the system owns. Later keys win, so a component cannot be
     * overridden by a topic that shouldn't have set that key.
     *
     * @return array<string, string>
     */
    private function variablesFor(Topic $topic, string $documentRef, ?CarbonInterface $at = null): array
    {
        $at ??= now();

        return [
            ...($topic->variables ?? []),
            ...($topic->component->fixed_variables ?? []),
            'DOCUMENT_REF' => $documentRef,
            // The client's own examples are `08.03.26`.
            'DATE' => $at->format('m.d.y'),
        ];
    }
}
