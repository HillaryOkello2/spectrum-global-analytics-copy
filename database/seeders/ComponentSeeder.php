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
     * (Assumptions §21, open question) — see PROVIDERS.
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

    /**
     * Which model writes — and, by FR-26, audits — each component.
     *
     * The client has not specified this (Assumptions §21, open question). It
     * used to be round-robin; it is now set from observed output. MF and HM
     * moved off GPT-4o on 2026-09-11: for both it produced short, generic
     * documents with padded or broken sentences, no named actors and missing
     * structure, and an independent audit failed every section of each.
     */
    public const PROVIDERS = [
        'DB' => 'anthropic',
        'WH' => 'gemini',
        'MF' => 'deepseek',
        'CC' => 'deepseek',
        'ES' => 'moonshot',
        'BS' => 'minimax',
        'RP' => 'anthropic',
        'WP' => 'gemini',
        'HM' => 'moonshot',
    ];

    public function run(): void
    {
        $providerIds = LlmProvider::pluck('id', 'driver')->all();

        // QC.txt is the client's SGA-QCP-v2, edited so that it audits the
        // document the generation prompts actually specify. As supplied it was
        // calibrated to a different schema and failed every draft on points
        // none of the nine product prompts ask for:
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
        //   - "end cleanly after Product Information" (2026-09-11) — every
        //     prompt places Section 10 after Product Information
        //
        // Everything else, including the whole verdict mechanism, is verbatim.
        // The Daily Brief is audited against QC-DB.txt instead — see
        // qaPromptFor().
        foreach (self::COMPONENTS as $index => $component) {
            Component::updateOrCreate(['code' => $component['code']], [
                ...$component,
                'prompt_template' => self::productPrompt($component),
                'topic_prompt' => self::topicPrompt($component),
                'qa_prompt_template' => self::qaPromptFor($component['code']),
                'assigned_llm_provider_id' => $providerIds[self::PROVIDERS[$component['code']]] ?? null,
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
     * The QA protocol a component is audited against: its own `QC-{code}.txt`
     * when one exists, the shared QC.txt otherwise.
     *
     * The shared protocol's strategic-only filter (§1) and decadal horizon (§5)
     * are right for eight of the nine products and impossible for the Daily
     * Brief, whose own prompt requires a 24-hour body: every Daily Brief ever
     * audited failed on exactly those two sections, under two different
     * auditors. QC-DB.txt keeps every other check and replaces those two,
     * judging tactical detail with the Necessity Test from SGA-QCP-v2.4.
     */
    public static function qaPromptFor(string $code): string
    {
        $own = database_path("seeders/prompts/QC-{$code}.txt");

        return is_file($own) ? file_get_contents($own) : self::promptFile('QC');
    }

    /**
     * The product prompt, with its metadata block turned into slots we can
     * fill and the output checklist appended.
     *
     * @param  array<string, mixed>  $component
     */
    public static function productPrompt(array $component): string
    {
        $prompt = self::normaliseMetadataBlock(
            self::promptFile($component['code']),
            [
                ...$component['variables'],
                ...array_keys($component['fixed_variables']),
                'DOCUMENT_REF',
                'DATE',
            ],
        );

        return rtrim($prompt)."\n\n".self::outputChecklist();
    }

    /**
     * Restates, as a closing checklist, requirements every prompt already sets
     * out but models were skipping — in audits on 2026-09-11 two Daily Briefs
     * and a Monthly Focus omitted the Table of Contents, a Crisis Simulation
     * ended with a sign-off paragraph, and documents padded paragraphs with
     * filler to reach the exact word counts. It adds no requirement of its own:
     * the prompt files stay verbatim, this is appended at seed time.
     *
     * No bracketed tokens — PromptRenderer would treat them as placeholders.
     */
    private static function outputChecklist(): string
    {
        return <<<'CHECKLIST'
        ======================================================================
        OUTPUT CHECKLIST — confirm each point before you finish
        ======================================================================
        1. Open with the cover block exactly as laid out in PART I: the SGA name and tagline, the product line, the title lines, the attribution statement, then the DOCUMENT REFERENCE and DATE.
        2. Reproduce the TABLE OF CONTENTS in full, immediately before Section 1 of PART I. It is part of the product, not an illustration.
        3. Write PART I and then PART II in exactly the sections and subsections specified above, in order, with nothing omitted and nothing added.
        4. Name the real states, institutions, systems and places your analysis relies on. Do not substitute generic phrasing such as "a major economy" or "central banks" for a specific actor you are drawing on.
        5. You have no access to data from the current reporting period. Do not state today's, this week's or this month's specific figures (tonnages, prices, auction results, counts, percentages) as fact. Name the real actors, describe the pattern, and set out its structural meaning.
        6. Meet each paragraph length with substance. Never pad a sentence with filler adverbs, stacked qualifiers or invented words to reach a word count.
        7. Every specific figure, date, place, force or market move must serve a structural or strategic claim made in the same paragraph. Do not string data points together as a news recap.
        8. Do not reproduce this prompt's instructions in the document: no word-count notes, no "STRICT RULE" lines, and no production headings such as "EXPLICIT ABSTRACT PAPER GENERATION".
        9. Stop after the last section specified for PART II. No closing summary, sign-off or commentary.
        CHECKLIST;
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
