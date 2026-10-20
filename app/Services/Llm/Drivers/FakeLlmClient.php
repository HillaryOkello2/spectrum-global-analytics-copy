<?php

namespace App\Services\Llm\Drivers;

use App\Services\Generation\RevisionPromptBuilder;
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

    /**
     * Marks a body this stub produced as a rewrite. The stub's own auditor
     * reads it, which is what lets the QA → revision → re-audit loop be driven
     * end to end locally with LLM_FAKE_QA_VERDICT=first_red.
     */
    private const REVISED = '_Revised draft (local development stub)._';

    public function __construct(private readonly string $modelId) {}

    public function generate(string $prompt): LlmResult
    {
        return new LlmResult(
            text: match (true) {
                // Checked first: a revision prompt also carries a document
                // after a `---` fence, so isQaPass() matches it too.
                $this->isRevisionPass($prompt) => $this->article($prompt, revised: true),
                $this->isQaPass($prompt) => $this->qaReport($prompt),
                $this->isTopicPass($prompt) => $this->topicVariables($prompt),
                default => $this->article($prompt),
            },
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

    /**
     * ReviseProductJob's prompt, which carries the specification, the rejected
     * draft and the audit report.
     */
    private function isRevisionPass(string $prompt): bool
    {
        return str_contains($prompt, RevisionPromptBuilder::MARKER);
    }

    /**
     * A component's topic_prompt asks for a JSON object and shows its schema.
     * Detecting that here is what lets the unattended daily/weekly path be
     * exercised end to end with LLM_FAKE=true.
     */
    private function isTopicPass(string $prompt): bool
    {
        return str_contains($prompt, 'Return ONLY a JSON object');
    }

    /**
     * Echo back the exact keys the prompt asked for, with plausible values.
     * TopicGenerator rejects a reply whose keys don't match, so the stub has to
     * read the requested schema rather than guess at it.
     */
    private function topicVariables(string $prompt): string
    {
        preg_match_all('/"([A-Z][A-Z0-9_]*)":/', $prompt, $matches);

        $seed = crc32($prompt);
        $subject = [
            'sovereign compute capacity and the fragmentation of semiconductor supply',
            'maritime chokepoint exposure across the Bab-el-Mandeb and Malacca corridors',
            'clearinghouse balkanization and the migration of settlement away from dollar rails',
            'critical mineral mercantilism and state capture of rare earth processing',
        ][$seed % 4];

        $values = [];

        foreach ($matches[1] as $index => $key) {
            $values[$key] = match (true) {
                $key === 'PRIMARY_TOPIC' => "A structural assessment of {$subject}. The analysis traces how the "
                    .'pressure propagates across adjacent financing, regulatory and logistics channels. It '
                    .'identifies the indicators that would signal a change of trajectory.',
                $key === 'PROJECT_FILE' => 'PROJECT '.['MERIDIAN SHOAL', 'GLASS MERIDIAN', 'IRON LATTICE', 'PALE HARBOUR'][$seed % 4],
                str_starts_with($key, 'CORE_THEME_') => Str::title($subject).' — vector '.($index + 1),
                str_contains($key, 'TITLE') => Str::title($subject),
                default => 'Structural exposure and second-order transmission across coupled global systems',
            };
        }

        return json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function article(string $prompt, bool $revised = false): string
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

            ...($revised ? [self::REVISED] : []),

            self::DISCLAIMER,
        ]);
    }

    /**
     * An audit report in the protocol's own output template, because the
     * verdict is parsed now (QaReportParser) and decides whether the document
     * reaches the vault. A stub that answered in free prose would leave the
     * whole gate untestable without spending credit.
     *
     * LLM_FAKE_QA_VERDICT drives it:
     *   green     — always passes (the default; keeps the happy path intact)
     *   red       — always fails, so the gate and `qa_failed` are reachable
     *   first_red — fails the first draft and passes the rewrite, which drives
     *               the whole QA → revision → re-audit loop
     */
    private function qaReport(string $prompt): string
    {
        $mode = (string) config('llm.fake_qa_verdict', 'green');

        $failing = match ($mode) {
            'red' => true,
            // The audited body follows the `---` fence; a rewrite this stub
            // produced carries its own marker.
            'first_red' => ! str_contains(Str::after($prompt, "\n\n---\n\n"), self::REVISED),
            default => false,
        };

        return implode("\n", [
            'SPECTRUM GLOBAL ANALYTICS',
            'QUALITY CONTROL & VETTING AUDIT REPORT',
            'DOCUMENT AUDITED: '.Str::limit($this->subject($prompt), 60),
            'AUDIT DATE: '.now()->toDateString(),
            '1. STRATEGIC-ONLY MANDATE VERIFICATION: '.($failing ? 'FAIL' : 'PASS'),
            '- Notes/Deficiencies: '.($failing
                ? 'Paragraph 3 recites tactical detail with no structural claim attached (Necessity Test).'
                : 'None.'),
            '2. STRUCTURAL INTEGRITY AUDIT: PASS',
            '- Notes/Deficiencies: None.',
            '3. SYNTAX & LINGUISTIC PRECISION AUDIT: PASS',
            '- Notes/Deficiencies: None.',
            '4. FACTUAL RIGOR & ANTI-HALLUCINATION AUDIT: '.($failing ? 'FAIL' : 'PASS'),
            '- Notes/Deficiencies: '.($failing
                ? 'Two current-period figures are stated without support.'
                : 'None.'),
            '5. TEMPORAL RIGOR & RELEVANCE AUDIT: PASS',
            '- Notes/Deficiencies: None.',
            str_repeat('=', 70),
            'FINAL VERDICT: '.($failing
                ? 'RED LIGHT - REJECTED FOR RE-RUN'
                : 'GREEN LIGHT - APPROVED FOR CLIENT RELEASE'),
            'RE-RUN INSTRUCTIONS (IF RED LIGHT): '.($failing
                ? 'Cut the tactical inventory in paragraph 3 or attach a structural claim to each fact retained. Remove the two unsupported current-period figures rather than replacing them.'
                : 'None.'),
            str_repeat('=', 70),
            '',
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
