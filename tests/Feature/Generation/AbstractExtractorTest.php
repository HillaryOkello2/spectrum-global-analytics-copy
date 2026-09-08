<?php

use App\Services\Generation\AbstractExtractor;

beforeEach(function (): void {
    $this->extractor = app(AbstractExtractor::class);
});

it('skips the table of contents and reads the real executive summary', function (): void {
    // The contents page names the Executive Summary pages before the section
    // itself. Matching the first occurrence returns nothing but headings.
    $body = <<<'MARKDOWN'
    # SPECTRUM GLOBAL ANALYTICS
    ## TABLE OF CONTENTS
    1. Executive Summary (1.1 Objective | 1.2 Outcome | 1.3 Key Takeaway)
    2. Analytical Assessment (2.1 Core Thesis | 2.2 Primary Themes)
    # PART I: ABSTRACT PAPER
    ## 1. Executive Summary
    **1.1 Objective**
    This brief assesses the fragmentation of the global monetary order and the sovereign responses it provokes.
    ## 2. Analytical Assessment
    **2.1 Core Thesis**
    This belongs to the next section and must never reach the public preview.
    MARKDOWN;

    expect($this->extractor->extract($body))
        ->toBe('This brief assesses the fragmentation of the global monetary order and the sovereign responses it provokes.');
});

it('reads a heading whichever way the provider decorates it', function (string $heading): void {
    $body = <<<MARKDOWN
    {$heading}
    The systemic consequences traced here run to the sovereign balance sheet and beyond.
    2. Analytical Assessment
    Not part of the abstract.
    MARKDOWN;

    expect($this->extractor->extract($body))
        ->toBe('The systemic consequences traced here run to the sovereign balance sheet and beyond.');
})->with([
    '## 1. Executive Summary',      // Claude
    '1. **Executive Summary**',     // GPT-4o
    '### EXECUTIVE SUMMARY',
    'Executive Summary',
]);

it('strips markdown so the preview card renders plain prose', function (): void {
    $body = <<<'MARKDOWN'
    ## 1. Executive Summary
    - **Objective.** The `dollar-clearing` system is being __weaponised__ against sovereign reserves.
    MARKDOWN;

    expect($this->extractor->extract($body))
        ->toBe('Objective. The dollar-clearing system is being weaponised against sovereign reserves.');
});

it('drops subsection labels and other structure', function (): void {
    $body = <<<'MARKDOWN'
    ## 1. Executive Summary
    **1.1 Objective**
    ---
    Sovereign actors are repositioning ahead of a structural realignment in reserve currency composition.
    MARKDOWN;

    // The label is structure, not prose, so only the sentence survives.
    expect($this->extractor->extract($body))
        ->toBe('Sovereign actors are repositioning ahead of a structural realignment in reserve currency composition.');
});

it('falls back to the opening prose when there is no executive summary', function (): void {
    $body = <<<'MARKDOWN'
    # SPECTRUM GLOBAL ANALYTICS
    ## Overview
    The document opens without the standard section numbering that every client prompt specifies.
    MARKDOWN;

    expect($this->extractor->extract($body))
        ->toBe('The document opens without the standard section numbering that every client prompt specifies.');
});

it('returns null when the document carries no prose at all', function (): void {
    expect($this->extractor->extract("# Title\n## 1. Executive Summary\n**1.1 Objective**"))->toBeNull();
});

it('caps the abstract so it stays a preview', function (): void {
    $sentence = 'Sovereign reserve composition is shifting across every major central bank balance sheet. ';
    $body = "## 1. Executive Summary\n".str_repeat($sentence, 60);

    expect(mb_strlen($this->extractor->extract($body)))->toBeLessThanOrEqual(1210);
});
