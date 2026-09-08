<?php

namespace App\Services\Generation;

use Illuminate\Support\Str;

/**
 * Lifts the public abstract out of a proofread document.
 *
 * Every product opens with PART I, the client's "SGA Abstract Paper", whose
 * section 1 is an Executive Summary. That is already the abstract, so a
 * proofreader is not asked to write a second one by hand — they submit the
 * corrected body and this pulls the preview text out of it.
 *
 * The result is plain prose: it renders on a public card, next to a lock, to
 * someone who has not paid, so no Markdown and no section numbering.
 */
class AbstractExtractor
{
    /**
     * products.abstract is text and the card excerpt is the first 160
     * characters, so a couple of paragraphs is the useful range.
     */
    private const MAX_LENGTH = 1200;

    /**
     * Where the abstract starts. Providers format the heading differently —
     * `## 1. Executive Summary` and `1. **Executive Summary**` are both real
     * output — so match the words, not the decoration.
     */
    private const OPENS = '/executive\s+summary/i';

    /**
     * Where it stops: the next top-level section, however it is decorated.
     * Section 2 is Analytical Assessment in every product line.
     */
    private const CLOSES = '/^[\s#>*\-]*\*{0,2}\s*2[.\)]\s/';

    /**
     * The Table of Contents names the Executive Summary too, several pages
     * before the real one. Rather than special-case the contents page — not
     * every provider emits one the same way, and GPT-4o omits the `PART I`
     * marker entirely — try each occurrence in turn and keep the first that is
     * actually followed by prose. A contents entry is followed by the next
     * contents entry, so it yields nothing and the search moves on.
     */
    public function extract(string $body): ?string
    {
        $lines = preg_split('/\R/', $body) ?: [];

        $paragraphs = $this->collect($lines);

        if ($paragraphs === []) {
            $paragraphs = $this->fallback($lines);
        }

        if ($paragraphs === []) {
            return null;
        }

        return Str::limit(implode("\n\n", $paragraphs), self::MAX_LENGTH, preserveWords: true);
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function collect(array $lines): array
    {
        foreach ($lines as $index => $line) {
            if (preg_match(self::OPENS, $line) !== 1) {
                continue;
            }

            $paragraphs = $this->readFrom($lines, $index + 1);

            if ($paragraphs !== []) {
                return $paragraphs;
            }
        }

        return [];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function readFrom(array $lines, int $offset): array
    {
        $paragraphs = [];

        foreach (array_slice($lines, $offset) as $line) {
            if (preg_match(self::CLOSES, $line) === 1) {
                break;
            }

            $this->keep($line, $paragraphs);
        }

        return $paragraphs;
    }

    /**
     * No Executive Summary heading found — take the first real prose in PART I
     * instead. Better a slightly wrong preview than a product that can never be
     * released, since the release queue skips anything without an abstract.
     *
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function fallback(array $lines): array
    {
        $paragraphs = [];

        foreach ($lines as $line) {
            $this->keep($line, $paragraphs);

            if (count($paragraphs) >= 2) {
                break;
            }
        }

        return $paragraphs;
    }

    /**
     * Keep a line only if it is prose. Headings, subsection labels, table rows
     * and horizontal rules are structure, not abstract.
     *
     * @param  array<int, string>  $paragraphs
     */
    private function keep(string $line, array &$paragraphs): void
    {
        $text = $this->toPlainText($line);

        if ($text === '' || mb_strlen($text) < 40) {
            return;
        }

        if (preg_match('/^\d+(\.\d+)*[.\)]?\s/', $text) === 1) {
            return;
        }

        $paragraphs[] = $text;
    }

    private function toPlainText(string $line): string
    {
        // Strip heading marks, bullets, quotes and emphasis, then collapse the
        // whitespace a wrapped PDF-derived line leaves behind.
        $text = preg_replace('/^[\s#>]*[-*+]?\s*/', '', $line) ?? $line;
        $text = str_replace(['**', '__', '`'], '', $text);
        $text = preg_replace('/\|/', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
