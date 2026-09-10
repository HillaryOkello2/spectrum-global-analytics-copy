<?php

use App\Exceptions\UnresolvedPromptPlaceholderException;
use App\Models\Component;
use App\Models\Topic;
use App\Services\Generation\PromptRenderer;
use Database\Seeders\ComponentSeeder;
use Database\Seeders\LlmProviderSeeder;

function renderer(): PromptRenderer
{
    return app(PromptRenderer::class);
}

it('substitutes declared placeholders', function (): void {
    expect(renderer()->render('Subject: [PRIMARY_TOPIC]. Ref: [DOCUMENT_REF].', [
        'PRIMARY_TOPIC' => 'sovereign compute capacity',
        'DOCUMENT_REF' => 'SGA.DB.001.08.26',
    ]))->toBe('Subject: sovereign compute capacity. Ref: SGA.DB.001.08.26.');
});

it('throws rather than shipping an unfilled placeholder', function (): void {
    // A document reaching a client with a literal "[PRIMARY_TOPIC]" in it is
    // worse than a failed generation task.
    expect(fn () => renderer()->render('Subject: [PRIMARY_TOPIC].', []))
        ->toThrow(UnresolvedPromptPlaceholderException::class);
});

it('leaves the domain vector codes in the prompts alone', function (): void {
    // The prompts require [SGA.D1.01]-style domain vector codes in the output.
    // Those are content, not slots, and must survive rendering untouched.
    expect(renderer()->render('Map against [SGA.D1] and [SGA.D12.03].', []))
        ->toBe('Map against [SGA.D1] and [SGA.D12.03].');
});

it('supplies the document reference and date itself', function (): void {
    $this->travelTo('2026-08-03');

    $component = Component::factory()->create([
        'prompt_template' => 'Ref [DOCUMENT_REF] dated [DATE] on [PRIMARY_TOPIC].',
        'variables' => ['PRIMARY_TOPIC'],
    ]);
    $topic = Topic::factory()->for($component)->auto(['PRIMARY_TOPIC' => 'chokepoint risk'])->create();

    expect(renderer()->renderProductPrompt($topic, 'SGA.DB.007.08.26'))
        // The client's own examples format the date as 08.03.26.
        ->toBe('Ref SGA.DB.007.08.26 dated 08.03.26 on chokepoint risk.');
});

it('prefers a hand-written topic prompt over the component template', function (): void {
    $component = Component::factory()->create(['prompt_template' => 'Template: [PRIMARY_TOPIC].']);
    $topic = Topic::factory()->for($component)->create(['prompt_text' => 'A prompt written by hand.']);

    expect(renderer()->renderProductPrompt($topic, 'SGA.DB.001.08.26'))
        ->toBe('A prompt written by hand.');
});

it('falls back to the component QA prompt when the topic has none', function (): void {
    $component = Component::factory()->create(['qa_prompt_template' => 'The vetting protocol.']);
    $topic = Topic::factory()->for($component)->auto()->create();

    expect(renderer()->renderQaPrompt($topic))->toBe('The vetting protocol.');
});

it('renders every seeded client prompt without an unresolved slot', function (): void {
    // The real guard on the prompt pack: if a PDF declares a placeholder the
    // seeder did not list in `variables`, this is where it surfaces.
    $this->seed(LlmProviderSeeder::class);
    $this->seed(ComponentSeeder::class);

    Component::all()->each(function (Component $component): void {
        $topic = Topic::factory()->for($component)->auto(
            array_fill_keys($component->variables, 'placeholder value'),
        )->create();

        expect(renderer()->renderProductPrompt($topic, 'SGA.XX.001.08.26'))
            ->not->toContain('[PRIMARY_TOPIC]')
            ->and(renderer()->renderTitle($topic))->not->toBeEmpty();
    });
});
