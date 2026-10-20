<?php

namespace App\Services\Generation;

use App\Enums\QaVerdict;
use App\Services\Generation\DTOs\QaReport;
use Illuminate\Support\Str;

/**
 * Reads a QA audit report the way a proofreader would: the verdict line, which
 * sections failed, and what the auditor said to fix.
 *
 * Until now nothing parsed any of this — the report was stored verbatim and
 * left for a human. It is parsed now because a RED LIGHT has to stop the
 * document reaching the vault and has to be fed back to the model.
 *
 * Parsing is deliberately forgiving about formatting (the protocols ask for
 * markdown and models wrap it in fences, bold it, or renumber it) and
 * deliberately strict about ambiguity: anything it cannot read confidently is
 * `Unknown`, which config decides what to do with.
 */
class QaReportParser
{
    /**
     * `FINAL VERDICT: GREEN LIGHT - APPROVED…`, allowing for **bold**, a
     * leading `#`, and the `======` rules the template puts around it.
     */
    private const VERDICT = '/FINAL\s+VERDICT\s*[:\-]?\s*\**\s*\[?\s*(.*)$/mi';

    /**
     * `1. STRATEGIC-ONLY MANDATE VERIFICATION: [PASS]` and its variants. The
     * models write this line every way markdown allows — as a heading
     * (`### 1. …`), bolded (`**FAIL**`), bracketed, or plain — so the prefixes
     * are all optional. Taken from 53 real audit reports across five vendors.
     */
    private const SECTION = '/^\s*#*\s*\**\s*(\d{1,2})\s*[\.\)]\s*\**\s*([^:\n]{3,120}?)\s*\**\s*:\s*\**\s*\[?\s*(PASS|FAIL)\b/mi';

    private const RERUN = '/RE-?RUN\s+INSTRUCTIONS[^:\n]*:\s*(.*?)(?:\n\s*={5,}|\z)/si';

    public function parse(string $report): QaReport
    {
        $sections = $this->sections($report);
        $verdict = $this->verdict($report, $sections);

        return new QaReport(
            verdict: $verdict,
            failedSections: array_keys(array_filter($sections, fn (string $result) => $result === 'FAIL')),
            rerunInstructions: $this->rerunInstructions($report),
            raw: $report,
        );
    }

    /**
     * The verdict line wins when it says one thing. When it is missing, or
     * when it is the template echoed back with both options still in it, the
     * per-section PASS/FAIL lines decide — a report with a FAIL in it is not a
     * pass whatever its footer says.
     *
     * @param  array<string, string>  $sections
     */
    private function verdict(string $report, array $sections): QaVerdict
    {
        if (preg_match_all(self::VERDICT, $report, $matches) > 0) {
            // The last occurrence: a model that reproduces the template before
            // filling it in states the real verdict second.
            $line = strtoupper((string) end($matches[1]));

            $green = str_contains($line, 'GREEN');
            $red = str_contains($line, 'RED');

            // Exactly one of them — both means the unfilled template.
            if ($green !== $red) {
                return $green ? QaVerdict::Green : QaVerdict::Red;
            }
        }

        if ($sections === []) {
            return QaVerdict::Unknown;
        }

        return in_array('FAIL', $sections, true) ? QaVerdict::Red : QaVerdict::Green;
    }

    /**
     * Section heading => PASS|FAIL, e.g. `1. STRATEGIC-ONLY MANDATE` => FAIL.
     *
     * @return array<string, string>
     */
    private function sections(string $report): array
    {
        if (preg_match_all(self::SECTION, $report, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $sections = [];

        foreach ($matches as [, $number, $heading, $result]) {
            $heading = trim(preg_replace('/\s+/', ' ', $heading));

            $sections[$number.'. '.Str::limit($heading, 80, '')] = strtoupper($result);
        }

        return $sections;
    }

    /**
     * What the auditor said to change. This is the most useful thing in the
     * report for a re-run, so it is pulled out separately and put at the top
     * of the revision prompt.
     */
    private function rerunInstructions(string $report): ?string
    {
        if (preg_match(self::RERUN, $report, $match) !== 1) {
            return null;
        }

        $instructions = trim((string) $match[1]);

        // The unfilled template placeholder, not an instruction.
        if ($instructions === '' || str_starts_with($instructions, '[Specific precise corrections')) {
            return null;
        }

        return $instructions;
    }
}
