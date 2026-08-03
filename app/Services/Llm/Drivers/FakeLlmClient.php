<?php

namespace App\Services\Llm\Drivers;

use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\LlmResult;
use Illuminate\Support\Str;

/**
 * Deterministic stub for local development and tests — no network calls.
 *
 * Output is multi-paragraph and varies per prompt so previews, excerpts and
 * full-body rendering are all exercisable without LLM credits. The closing line
 * marks every body as placeholder text, so stub content cannot be mistaken for
 * real analysis if it reaches a screen.
 */
class FakeLlmClient implements LlmClient
{
    private const DISCLAIMER = '_Placeholder content produced by the local development stub (LLM_FAKE=true). This is not real analysis._';

    public function __construct(private readonly string $modelId) {}

    public function generate(string $prompt): LlmResult
    {
        return new LlmResult(
            text: $this->isQaPass($prompt) ? $this->qaReport($prompt) : $this->article($prompt),
            model: $this->modelId,
        );
    }

    /**
     * RunQaPromptJob appends the drafted body after a `---` fence; anything else
     * is a first-pass generation prompt.
     */
    private function isQaPass(string $prompt): bool
    {
        return str_contains($prompt, "\n\n---\n\n");
    }

    private function article(string $prompt): string
    {
        $subject = $this->subject($prompt);
        $seed = crc32($prompt);
        $horizon = [90, 120, 180, 270][$seed % 4];
        $confidence = ['low', 'moderate', 'moderate-to-high', 'high'][($seed >> 3) % 4];

        return implode("\n\n", [
            "This assessment examines {$subject}, setting out the drivers shaping the current picture, the actors positioned to influence outcomes, and the indicators most likely to signal a change of trajectory within the next {$horizon} days. Judgements are held at {$confidence} confidence and should be read alongside the reporting cycle that follows.",

            '## Key judgements',

            "- Structural pressures are unlikely to resolve inside the current cycle; incremental adjustment remains the base case.\n"
                ."- Second-order effects should surface first in adjacent supply, financing and regulatory channels rather than in headline indicators.\n"
                .'- A materially different outcome would require at least two of the drivers below moving in the same direction at once.',

            '## Drivers and constraints',

            'The dominant driver remains the interaction between institutional capacity and the pace at which commitments are converted into delivery. Where that gap widens, the parties involved have historically absorbed the difference through informal accommodation rather than formal renegotiation, which suppresses visible signals while leaving the underlying exposure intact.',

            'Constraints run the other way. Budgetary sequencing, staffing depth and the ordering of external obligations all limit how quickly posture can change, so abrupt reversals are less probable than a sustained drift. The absence of public movement should be treated as weak evidence either way.',

            '## Indicators to watch',

            'Three indicators carry the most diagnostic weight over this horizon: the cadence of official engagement, movement in the terms attached to financing or procurement, and any reallocation of personnel toward contingency planning. Two or more moving together would justify revisiting the judgements above ahead of schedule.',

            '## Outlook',

            'On balance the trajectory favours continuity with gradual adjustment. The principal risk to this view is a shock originating outside the frame of this assessment, which would compress the timelines above rather than change their direction.',

            self::DISCLAIMER,
        ]);
    }

    private function qaReport(string $prompt): string
    {
        $score = 82 + (crc32($prompt) % 15);

        return implode("\n\n", [
            "Quality assurance review complete — overall score {$score}/100. The draft is structurally sound and suitable for proofreading.",

            "**Structure.** Sections follow the expected order: summary, key judgements, drivers, indicators, outlook. No section is empty.\n"
                ."**Tone.** Analytic register maintained throughout; no advocacy language detected.\n"
                .'**Claims.** Assertions are hedged appropriately and confidence is stated explicitly.',

            'Recommended proofreader checks: verify terminology against the house style guide, confirm the stated confidence matches the strength of the reasoning, and tighten any repetition between the drivers and outlook sections.',

            self::DISCLAIMER,
        ]);
    }

    /**
     * Best-effort subject lifted from the prompt so each product reads as being
     * about its own topic.
     */
    private function subject(string $prompt): string
    {
        $firstLine = trim(Str::before(trim($prompt), "\n"));

        return $firstLine === '' ? 'the subject of this brief' : Str::lower(Str::limit($firstLine, 120, ''));
    }
}
