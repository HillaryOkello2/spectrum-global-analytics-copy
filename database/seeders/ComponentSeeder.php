<?php

namespace Database\Seeders;

use App\Models\Component;
use App\Models\LlmProvider;
use Illuminate\Database\Seeder;

class ComponentSeeder extends Seeder
{
    /**
     * The nine Components, taken from the client's August prompt pack — one
     * component per finalised prompt (SPECTRUM ANALYTICS FOLDER/PROMPTS).
     *
     * This replaces the A1–A9 placeholders that stood in for Blueprint Annex 2:
     * Abstract Papers had no prompt and was dropped, White Papers Corporate and
     * Governmental collapse into one WP (there is one WP prompt), and that frees
     * the two slots for Close-Circuit Briefs and Crisis Simulations.
     *
     * `ref_code` is the token the client's own [DOCUMENT_REF] examples use and is
     * what a product code carries; it differs from `code` for BS, CC and HM.
     *
     * `variables` are the placeholders the LLM fills per run; `fixed` are the
     * ones with a constant value for the whole series. DOCUMENT_REF and DATE are
     * supplied by the system and appear in neither list.
     *
     * The Component→LLM mapping is still not specified by the client
     * (Assumptions §21, open question) — assigned round-robin as a placeholder.
     */
    public const COMPONENTS = [
        [
            'code' => 'DB',
            'ref_code' => 'DB',
            'name' => 'Daily Strategic Intelligence Analytics Brief',
            'batch' => 1,
            'generation_frequency' => 'daily',
            'variables' => ['PRIMARY_TOPIC', 'BYLINE'],
            'fixed_variables' => ['DOCUMENT_TITLE' => 'DAILY STRATEGIC INTELLIGENCE ANALYTICS BRIEF'],
            'title_template' => '[DOCUMENT_TITLE] — [DATE]',
        ],
        [
            'code' => 'WH',
            'ref_code' => 'WH',
            'name' => 'Weekly Strategic Intelligence Analytics Highlights',
            'batch' => 1,
            'generation_frequency' => 'weekly',
            'variables' => ['PRIMARY_TOPIC', 'BYLINE'],
            'fixed_variables' => ['DOCUMENT_TITLE' => 'WEEKLY STRATEGIC INTELLIGENCE ANALYTICS HIGHLIGHTS'],
            'title_template' => '[DOCUMENT_TITLE] — [DATE]',
        ],
        [
            'code' => 'MF',
            'ref_code' => 'MF',
            'name' => 'Monthly Strategic Intelligence Analytics Focus',
            'batch' => 1,
            'generation_frequency' => 'monthly',
            'variables' => ['PRIMARY_TOPIC', 'BYLINE'],
            'fixed_variables' => ['DOCUMENT_TITLE' => 'MONTHLY STRATEGIC INTELLIGENCE ANALYTICS FOCUS'],
            'title_template' => '[DOCUMENT_TITLE] — [DATE]',
        ],
        [
            // The prompt is titled "MONTHLY … CLOSE-CIRCUIT BRIEF" and has a fixed
            // series title, so it has the same shape as DB/WH/MF. The meeting
            // notes named only daily/weekly/monthly, so this is left
            // admin-triggered pending the client's answer — switching it on is
            // a one-row update to generation_frequency, no code change.
            'code' => 'CC',
            'ref_code' => 'CB',
            'name' => 'Monthly Sovereign Close-Circuit Brief',
            'batch' => 2,
            'generation_frequency' => null,
            'variables' => ['DOCUMENT_TITLE', 'CORE_THEME_1', 'CORE_THEME_2', 'CORE_THEME_3', 'CORE_THEME_4'],
            'fixed_variables' => ['SERIES_TITLE' => 'MONTHLY SOVEREIGN STRATEGIC INTELLIGENCE CLOSE-CIRCUIT ANALYTICS BRIEF'],
            'title_template' => '[DOCUMENT_TITLE]',
        ],
        [
            'code' => 'ES',
            'ref_code' => 'ES',
            'name' => 'Analytics Essay Series',
            'batch' => 2,
            'generation_frequency' => null,
            'variables' => ['PRIMARY_TOPIC', 'DOCUMENT_TITLE', 'BYLINE'],
            'fixed_variables' => [],
            'title_template' => '[DOCUMENT_TITLE]',
        ],
        [
            'code' => 'BS',
            'ref_code' => 'BK',
            'name' => 'Analytics Book Series',
            'batch' => 2,
            'generation_frequency' => null,
            'variables' => ['PRIMARY_TOPIC', 'BOOK_VOLUME_TITLE', 'BYLINE'],
            'fixed_variables' => [],
            'title_template' => '[BOOK_VOLUME_TITLE]',
        ],
        [
            'code' => 'RP',
            'ref_code' => 'RP',
            'name' => 'Strategic Analytics Research Papers',
            'batch' => 3,
            'generation_frequency' => null,
            'variables' => ['PRIMARY_TOPIC', 'DOCUMENT_TITLE', 'BYLINE'],
            'fixed_variables' => [],
            'title_template' => '[DOCUMENT_TITLE]',
        ],
        [
            'code' => 'WP',
            'ref_code' => 'WP',
            'name' => 'Analytics White Papers',
            'batch' => 3,
            'generation_frequency' => null,
            'variables' => ['PRIMARY_TOPIC', 'DOCUMENT_TITLE', 'DOCUMENT_SUBTITLE'],
            'fixed_variables' => [],
            'title_template' => '[DOCUMENT_TITLE]',
        ],
        [
            'code' => 'HM',
            'ref_code' => 'CS',
            'name' => 'High Magnitude Crisis Simulation Project',
            'batch' => 3,
            'generation_frequency' => null,
            'variables' => ['PROJECT_FILE', 'DOCUMENT_TITLE', 'TARGET_THREAT_MATRIX'],
            'fixed_variables' => [],
            'title_template' => '[PROJECT_FILE]: [DOCUMENT_TITLE]',
        ],
    ];

    public function run(): void
    {
        $providerIds = LlmProvider::orderBy('id')->pluck('id')->all();

        // QC.txt is the client's SGA-QCP-v2, edited (2026-09-07/08) so that it
        // audits the document the generation prompts actually specify. As
        // supplied it was calibrated to a different schema and failed every
        // draft on four points none of the nine product prompts ask for:
        //
        //   - the `SGA.P1`-`SGA.P12` pillar taxonomy (Section 6, and the vector
        //     codes in 2.1/4.2) — the prompts specify First/Second/Third Order
        //   - a "Tiers 3.5.1-3.5.6" client list on the cover — 3.5.x appears in
        //     no product prompt
        //   - core sections "Themes/Phases I-IV" and subsections x.1 to x.5 —
        //     the prompts specify ten named sections of exactly x.1 to x.3
        //   - six subscriber-tier advisories in Section 7 — the prompts specify
        //     three: Primary, Secondary, Third Tier ("third tier" being the
        //     third order of consequence, not subscriber tier three)
        //
        // Everything else, including the whole verdict mechanism, is verbatim.
        $qaPrompt = self::promptFile('QC');

        foreach (self::COMPONENTS as $index => $component) {
            Component::updateOrCreate(['code' => $component['code']], [
                ...$component,
                'prompt_template' => self::productPrompt($component),
                'topic_prompt' => self::topicPrompt($component),
                'qa_prompt_template' => $qaPrompt,
                'assigned_llm_provider_id' => $providerIds === [] ? null : $providerIds[$index % count($providerIds)],
                'is_transactional' => false,
                'queue_name' => 'llm-'.strtolower($component['code']),
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * The client's prompts, extracted verbatim from the August PDFs. Kept as
     * files rather than PHP heredocs — they run to ~150 lines each.
     */
    public static function promptFile(string $code): string
    {
        return file_get_contents(database_path("seeders/prompts/{$code}.txt"));
    }

    /**
     * The product prompt, with its metadata block turned into slots we can fill.
     *
     * @param  array<string, mixed>  $component
     */
    public static function productPrompt(array $component): string
    {
        return self::normaliseMetadataBlock(
            self::promptFile($component['code']),
            [
                ...$component['variables'],
                ...array_keys($component['fixed_variables']),
                'DOCUMENT_REF',
                'DATE',
            ],
        );
    }

    /**
     * Section 1 of every client prompt is a fill-in form written as
     *
     *     [DOCUMENT_REF]: <e.g., SGA.DB.001.08.26>
     *
     * where the bracketed token is the field LABEL and the angle-bracketed part
     * is the value to supply. Left as-is, PromptRenderer substitutes the label
     * and the example survives in value position — so the prompt ends up
     * carrying two document codes and naming the wrong one as the value. That is
     * not theoretical: a test run allocated SGA.DB.000.00.00 and the model
     * printed the example, SGA.DB.001.08.26, into the document instead.
     *
     * Rewriting each field to
     *
     *     DOCUMENT_REF: [DOCUMENT_REF]
     *
     * keeps the client's label, drops the example, and puts the slot where the
     * value belongs. Only section 1 is touched, and only for tokens this
     * component actually declares — elsewhere the prompts use `[TOKEN]: [TOKEN]`
     * as a genuine layout instruction (HM prints `[PROJECT_FILE]:
     * [DOCUMENT_TITLE]` on the cover), which must survive untouched.
     *
     * @param  array<int, string>  $tokens
     */
    private static function normaliseMetadataBlock(string $prompt, array $tokens): string
    {
        $lines = explode("\n", $prompt);
        $start = null;
        $end = null;

        foreach ($lines as $i => $line) {
            if ($start === null && str_contains($line, 'CORE DOCUMENT METADATA')) {
                // Skip the ==== rule that closes the heading.
                $start = $i + 2;

                continue;
            }

            if ($start !== null && $i > $start && str_starts_with($line, '=====')) {
                $end = $i;

                break;
            }
        }

        if ($start === null || $end === null) {
            return $prompt;
        }

        // One pass, field by field. Iterating token-by-token with a regex does
        // not work here: once a field has been rewritten it no longer starts
        // with a bracket, so the next token's "stop at the following field"
        // lookahead runs past it and swallows it.
        $rewritten = [];
        $current = null;

        foreach (array_slice($lines, $start, $end - $start) as $line) {
            if (preg_match('/^\[([A-Z][A-Z0-9_]*)\]:/', $line, $match) === 1) {
                $current = $match[1];

                // A field whose value is itself a placeholder is a layout
                // instruction, not a form field — leave it exactly as written.
                $isSlot = in_array($current, $tokens, true)
                    && preg_match('/^\[[A-Z][A-Z0-9_]*\]:\s*\[/', $line) !== 1;

                $rewritten[] = $isSlot ? $current.': ['.$current.']' : $line;
                $current = $isSlot ? $current : null;

                continue;
            }

            // Continuation of a value spec wrapped over several lines. It was
            // folded into the slot above, so drop it.
            if ($current !== null && trim($line) !== '') {
                continue;
            }

            $current = null;
            $rewritten[] = $line;
        }

        return implode("\n", [
            ...array_slice($lines, 0, $start),
            ...$rewritten,
            ...array_slice($lines, $end),
        ]);
    }

    /**
     * Asks the assigned model to invent one run's worth of variables and return
     * them as JSON. This is what lets a recurring product generate unattended:
     * the scheduler calls this, gets a topic back, then feeds it into the
     * component's prompt_template.
     *
     * The strict "JSON object, these keys, nothing else" framing matters —
     * TopicGenerator rejects a response whose keys don't match `variables`.
     */
    private static function topicPrompt(array $component): string
    {
        $keys = $component['variables'];
        $series = $component['fixed_variables']['DOCUMENT_TITLE']
            ?? $component['fixed_variables']['SERIES_TITLE']
            ?? $component['name'];

        $schema = json_encode(
            array_fill_keys($keys, '<value>'),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        return <<<PROMPT
        You are the SPECTRUM GLOBAL ANALYTICS (SGA) Internal AI Operations System, working as
        the commissioning editor for the series "{$series}".

        Select the single most consequential subject this edition should cover, judged at the
        grand-strategic, systemic, macroeconomic and geopolitical level only. Ground it in the
        current real-world operating environment. Reject anything that reads as daily news
        reporting, tactical battlefield detail, or micro-corporate logistics.

        Return ONLY a JSON object with exactly these keys and no others. No markdown fences,
        no commentary before or after:

        {$schema}

        Field rules:
        - Every value is a single plain-text string. No nested objects or arrays.
        - PRIMARY_TOPIC, when present, is two to three sentences describing the subject in
          detail.
        - Titles and bylines are a single line each, in the sovereign-grade, unhedged SGA
          register, with no trailing punctuation.
        - CORE_THEME_1 through CORE_THEME_4, when present, must be four distinct,
          non-overlapping strategic themes.
        - PROJECT_FILE, when present, is a two-word operation codename in capitals, prefixed
          with "PROJECT ".
        - TARGET_THREAT_MATRIX, when present, is a single line naming the coupled threats,
          at most 200 characters. It is printed as the document's byline, not as prose.
        PROMPT;
    }
}
