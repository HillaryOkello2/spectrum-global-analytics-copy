<?php

use App\Models\Component;
use Database\Seeders\ComponentSeeder;
use Database\Seeders\LlmProviderSeeder;

it('audits the Daily Brief against its own protocol and everything else against the shared one', function (): void {
    $this->seed(LlmProviderSeeder::class);
    $this->seed(ComponentSeeder::class);

    $shared = ComponentSeeder::promptFile('QC');
    $daily = ComponentSeeder::promptFile('QC-DB');

    expect(Component::where('code', 'DB')->value('qa_prompt_template'))->toBe($daily);

    Component::where('code', '!=', 'DB')->get()->each(
        fn (Component $component) => expect($component->qa_prompt_template)->toBe($shared),
    );
});

it('requires the 24-hour body in the Daily Brief protocol instead of banning it', function (): void {
    // The shared protocol's zero-tolerance ban on daily news and its
    // decadal-only horizon failed every Daily Brief ever audited — five
    // audits of four briefs, by two different auditors.
    expect(ComponentSeeder::promptFile('QC-DB'))
        ->not->toContain('MUST DETECT AND IMMEDIATELY REJECT')
        ->not->toContain('10-to-30-year')
        ->toContain('Their presence is never a breach')
        ->toContain('Necessity Test')
        ->toContain("PART II's horizon is the next 24 hours by specification");
});

it('keeps every other check in the Daily Brief protocol', function (): void {
    // Relaxing §1 and §5 must not relax anything else: a brief missing its
    // Table of Contents, as one did on 2026-09-11, still has to fail.
    expect(ComponentSeeder::promptFile('QC-DB'))
        ->toContain('Ensure the Table of Contents is present')
        ->toContain('HALLUCINATION PREVENTION & FACTUAL RIGOR AUDIT')
        ->toContain('Flag and reject speculative hedges')
        ->toContain('FINAL VERDICT');
});

it('no longer contradicts the prompts on where a document ends', function (string $file): void {
    // Every product prompt places Section 10 after Product Information; the
    // protocol used to demand the document end there.
    expect(ComponentSeeder::promptFile($file))
        ->not->toContain('end cleanly after Product Information.')
        ->toContain('that order is compliant');
})->with(['QC', 'QC-DB']);

it('lists every mandated section of the Daily Brief body', function (): void {
    // An earlier draft omitted Section 3 and listed a subsection in its place,
    // so the auditor failed a compliant brief for carrying a section the
    // protocol did not know about.
    $daily = ComponentSeeder::promptFile('QC-DB');

    foreach ([
        '24-Hour Systemic Overview & Flashpoint Analysis',
        'Primary Threat Vector & Operational Friction',
        'Multi-Sphere Cross-Domain Impact Matrix',
        'Immediate Operational Directives & Tactical Advisories',
        'Strategic Synthesis & Next-24-Hour Outlook',
    ] as $section) {
        expect($daily)->toContain($section);
        expect(ComponentSeeder::promptFile('DB'))->toContain($section);
    }
});

it('judges PART II against its own layout, not PART I\'s', function (string $file): void {
    // RP and HM specify section-only PART II bodies and MF two subsections;
    // all three were failed for not having x.1-x.3.
    expect(ComponentSeeder::promptFile($file))
        ->toContain("Do not apply PART I's x.1–x.3 rule to PART II");
})->with(['QC', 'QC-DB']);

it('fails what an auditor can judge, not what it cannot verify', function (string $file): void {
    // One run failed WP for figures it could not verify and HM for having none.
    expect(ComponentSeeder::promptFile($file))
        ->toContain('Do not fail a plausible figure solely because the document does not source it')
        ->toContain('Do not reward vagueness');
})->with(['QC', 'QC-DB']);

it('assigns every component to its chosen model', function (): void {
    $this->seed(LlmProviderSeeder::class);
    $this->seed(ComponentSeeder::class);

    expect(array_keys(ComponentSeeder::PROVIDERS))
        ->toEqualCanonicalizing(array_column(ComponentSeeder::COMPONENTS, 'code'));

    foreach (ComponentSeeder::PROVIDERS as $code => $driver) {
        expect(Component::where('code', $code)->first()->assignedLlmProvider->driver)->toBe($driver);
    }

    // GPT-4o wrote short, generic, badly structured documents for both of its
    // components; an independent audit failed every section of each.
    expect(Component::whereHas('assignedLlmProvider', fn ($query) => $query->where('driver', 'openai'))->exists())
        ->toBeFalse();
});

it('closes every product prompt with the output checklist', function (): void {
    foreach (ComponentSeeder::COMPONENTS as $component) {
        expect(ComponentSeeder::productPrompt($component))
            ->toContain('OUTPUT CHECKLIST')
            ->toContain('Reproduce the TABLE OF CONTENTS in full')
            ->toContain('No closing summary, sign-off or commentary');
    }
});

it('judges tactical detail by necessity in the shared protocol, not zero tolerance', function (): void {
    // The zero-tolerance filter failed the Weekly Highlights for its mandated
    // 7-day consolidation, the Close-Circuit Brief for naming the forces in
    // its own Hormuz theme, and the Crisis Simulation for its phased timeline.
    expect(ComponentSeeder::promptFile('QC'))
        ->not->toContain('MUST DETECT AND IMMEDIATELY REJECT')
        ->toContain('NECESSITY TEST')
        ->toContain('their presence is never a breach');
});

it('asks writers to apply the same test the auditor does', function (): void {
    // Both auditors failed the same Daily Brief paragraphs: four market data
    // points stacked with no structural claim carrying them.
    foreach (ComponentSeeder::COMPONENTS as $component) {
        expect(ComponentSeeder::productPrompt($component))
            ->toContain('must serve a structural or strategic claim made in the same paragraph');
    }
});

it('tells writers they cannot see current-period data', function (): void {
    // The Daily Brief and Monthly Focus opened on this month's gold tonnages
    // and today's auction tail: figures no model without live data can know.
    // Both auditors failed those paragraphs.
    foreach (ComponentSeeder::COMPONENTS as $component) {
        expect(ComponentSeeder::productPrompt($component))
            ->toContain('You have no access to data from the current reporting period')
            ->toContain("Do not reproduce this prompt's instructions");
    }
});

it('does not fail the specified cover or PART II numbering', function (): void {
    // An auditor called the mandated tagline an extraneous title, and failed
    // WP for PART II headings that are exactly what WP.txt specifies.
    expect(ComponentSeeder::promptFile('QC'))
        ->toContain('A New Security Architecture for a Fractured World')
        ->toContain('PART II heading numbers run independently of PART I');

    expect(ComponentSeeder::promptFile('QC-DB'))
        ->toContain('A New Security Architecture for a Fractured World');
});
