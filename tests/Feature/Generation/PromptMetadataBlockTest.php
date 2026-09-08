<?php

use App\Models\Component;
use App\Services\Generation\PromptRenderer;
use Database\Seeders\ComponentSeeder;

it('turns every declared metadata field into a fillable slot', function (array $component): void {
    $prompt = ComponentSeeder::productPrompt($component);
    $block = metadataBlock($prompt);

    $expected = [
        ...$component['variables'],
        ...array_keys($component['fixed_variables']),
        'DOCUMENT_REF',
        'DATE',
    ];

    foreach ($expected as $token) {
        expect($block)->toContain("{$token}: [{$token}]");
    }

    // The client writes each field as `[TOKEN]: <e.g., …>`, where the bracket is
    // the label. Leaving the example in value position is what let a model print
    // SGA.DB.001.08.26 into a document whose code was SGA.DB.000.00.00.
    expect($block)->not->toContain('<e.g.,')
        ->and($block)->not->toMatch('/^\[[A-Z][A-Z0-9_]*\]:/m');
})->with(fn () => array_map(fn ($c) => [$c], ComponentSeeder::COMPONENTS));

it('leaves layout instructions outside the metadata block alone', function (): void {
    $hm = collect(ComponentSeeder::COMPONENTS)->firstWhere('code', 'HM');

    // The cover prints one placeholder against another. That is a layout
    // instruction, not a form field, and must survive untouched.
    expect(ComponentSeeder::productPrompt($hm))
        ->toContain('[PROJECT_FILE]: [DOCUMENT_TITLE]');
});

it('renders a metadata block with no placeholders left', function (): void {
    $component = Component::factory()->create([
        'prompt_template' => ComponentSeeder::productPrompt(
            collect(ComponentSeeder::COMPONENTS)->firstWhere('code', 'DB'),
        ),
        'variables' => ['PRIMARY_TOPIC', 'BYLINE'],
        'fixed_variables' => ['DOCUMENT_TITLE' => 'DAILY BRIEF'],
    ]);

    $topic = $component->topics()->create([
        'title' => 'Test edition',
        'frequency' => 'daily',
        'source' => 'auto',
        'variables' => ['PRIMARY_TOPIC' => 'A subject', 'BYLINE' => 'A desk'],
        'is_active' => true,
    ]);

    $rendered = app(PromptRenderer::class)->renderProductPrompt($topic, 'SGA.DB.007.09.26');

    expect(metadataBlock($rendered))
        ->toContain('DOCUMENT_REF: SGA.DB.007.09.26')
        ->toContain('PRIMARY_TOPIC: A subject')
        ->toContain('DOCUMENT_TITLE: DAILY BRIEF')
        // The old example code must not survive anywhere in the prompt: two
        // codes in one prompt is how the wrong one ends up in the document.
        ->and($rendered)->not->toContain('SGA.DB.001.08.26');
});

function metadataBlock(string $prompt): string
{
    $lines = explode("\n", $prompt);
    $start = null;

    foreach ($lines as $i => $line) {
        if ($start === null && str_contains($line, 'CORE DOCUMENT METADATA')) {
            $start = $i + 2;

            continue;
        }

        if ($start !== null && $i > $start && str_starts_with($line, '=====')) {
            return implode("\n", array_slice($lines, $start, $i - $start));
        }
    }

    return '';
}
