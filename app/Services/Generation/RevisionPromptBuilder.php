<?php

namespace App\Services\Generation;

use App\Models\GenerationTask;
use App\Models\QaReview;

/**
 * Builds the prompt that sends a failed document back to the model together
 * with the audit that failed it.
 *
 * The original product prompt is included in full, not summarised: the audit
 * checks the document against that specification (cover, Table of Contents,
 * section order, the six client tiers), so a model asked to fix the document
 * without it would correct the cited faults and introduce new ones.
 *
 * This is expensive — the spec, the whole previous draft and the whole audit
 * in one call — which is why config('qa.max_revisions') is deliberately low.
 */
class RevisionPromptBuilder
{
    /**
     * Marks the prompt as a revision. The fake driver keys off it, and it is
     * the one unambiguous way to tell a revision prompt from a QA prompt —
     * both carry a document after a `---` fence.
     */
    public const MARKER = 'REVISION INSTRUCTIONS (MANDATORY)';

    public function __construct(private readonly PromptRenderer $renderer) {}

    public function build(GenerationTask $task, QaReview $review): string
    {
        $product = $task->product;

        $specification = $this->renderer->renderProductPrompt($task->topic, $product->code);

        $corrections = $review->rerun_instructions
            ?? 'Correct every deficiency itemised in the audit report below.';

        $failed = $review->failed_sections === null || $review->failed_sections === []
            ? 'the sections marked FAIL in the report below'
            : implode(', ', $review->failed_sections);

        return implode("\n\n", [
            'You previously produced the document below. It FAILED quality control and must be rewritten.',

            self::MARKER,
            implode("\n", [
                '1. Rewrite the document IN FULL. Return the complete corrected document and nothing else —',
                '   no preamble, no commentary on the changes, no audit response, no sign-off.',
                '2. Fix every deficiency the audit report raises. Failed sections: '.$failed.'.',
                '3. Keep everything the audit did not fault. Do not restructure, re-title, renumber or',
                '   re-reference passages it passed, and keep the same document reference code.',
                '4. The original specification below still governs the document in full. Where the audit and',
                '   the specification appear to conflict, the specification wins and the conflict is noted',
                '   nowhere in the document.',
                '5. Name the real states, institutions, systems and places the analysis relies on. Do not',
                '   answer the audit by retreating into generic phrasing ("a major economy", "dominant',
                '   technological powers") — an unnamed actor is itself an audit failure.',
                '6. You have no access to data from the current reporting period. Do not state this period\'s',
                '   specific figures (tonnages, prices, counts, percentages) as fact. Name the real actors,',
                '   describe the pattern, and set out its structural meaning. An invented number is the',
                '   failure the audit exists to catch; writing a new one to satisfy it fails the next audit.',
            ]),

            'AUDITOR\'S REQUIRED CORRECTIONS',
            $corrections,

            '--- ORIGINAL SPECIFICATION ---',
            $specification,

            '--- PREVIOUS DRAFT (REJECTED) ---',
            (string) $product->body,

            '--- QUALITY CONTROL AUDIT REPORT (ROUND '.($review->round + 1).') ---',
            $review->report,

            'Return only the corrected document.',
        ]);
    }
}
