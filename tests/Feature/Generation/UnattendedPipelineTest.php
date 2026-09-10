<?php

use App\Enums\ProductStatus;
use App\Jobs\DispatchDueTopicsJob;
use App\Models\Component;
use App\Models\GenerationTask;
use App\Models\Product;
use App\Models\Topic;
use App\Services\Generation\GenerationPipeline;
use App\Services\Generation\PromptRenderer;
use App\Services\Generation\TopicGenerator;
use App\Services\Publishing\ReleaseService;
use Database\Seeders\DatabaseSeeder;

/**
 * The whole point of the 2026-08 prompt pack: a daily brief that goes from
 * nothing to a published catalogue entry without anybody filing a topic.
 * Runs on the sync queue with LLM_FAKE, so it executes inline.
 */
it('takes the daily brief from scheduler tick to published catalogue entry', function (): void {
    $this->travelTo('2026-08-03 00:05:00');
    $this->seed(DatabaseSeeder::class);

    app(DispatchDueTopicsJob::class)->handle(app(GenerationPipeline::class));

    $component = Component::where('code', 'DB')->firstOrFail();
    $topic = Topic::where('component_id', $component->id)->firstOrFail();

    // The scheduler commissioned the topic; nobody typed it.
    expect($topic->source)->toBe(Topic::SOURCE_AUTO)
        ->and($topic->variables)->toHaveKeys(['PRIMARY_TOPIC', 'BYLINE'])
        ->and($topic->title)->toBe('DAILY STRATEGIC INTELLIGENCE ANALYTICS BRIEF — 08.03.26');

    $product = Product::where('component_id', $component->id)->firstOrFail();

    expect($product->code)->toBe('SGA.DB.001.08.26')
        ->and($product->byline)->not->toBeEmpty()
        ->and($product->body)->not->toBeEmpty()
        // The abstract is a proofreader's job — the model never writes one.
        ->and($product->abstract)->toBeNull()
        ->and($product->status)->toBe(ProductStatus::AwaitingProofreading);

    $task = GenerationTask::where('product_id', $product->id)->firstOrFail();
    expect($task->qa_result)->not->toBeEmpty();

    // Proofread, redact, release.
    $reviewer = admin();
    $this->actingAs($reviewer)->postJson(route('api.admin.tasks.open', $task))->assertOk();
    // The proofreader submits the document alone: the title came from the
    // component's title_template when the shell was created, and the abstract
    // is lifted from the body.
    $title = $product->title;

    $this->actingAs($reviewer)->postJson(route('api.admin.tasks.proofread', $task), [
        'body' => $product->body,
    ])->assertOk();

    // publishing.redaction is off, so proofreading approves outright — there is
    // no redaction pass between the two.
    expect($product->refresh()->abstract)->not->toBeNull();

    expect($product->refresh()->status)->toBe(ProductStatus::Approved)
        // Approval is not publication.
        ->and($product->published_at)->toBeNull();

    app(ReleaseService::class)->releaseBatch();

    expect($product->refresh()->status)->toBe(ProductStatus::Published);

    // And it is now browsable by code on the public catalogue.
    $this->getJson(route('api.catalog.components.products.index', 'DB'))
        ->assertOk()
        ->assertJsonPath('data.0.code', 'SGA.DB.001.08.26')
        ->assertJsonPath('data.0.title', $title);
});

it('renders the client prompt with no placeholder left behind', function (): void {
    $this->travelTo('2026-08-03');
    $this->seed(DatabaseSeeder::class);

    $component = Component::where('code', 'DB')->firstOrFail();
    $topic = app(TopicGenerator::class)->generate($component);

    $prompt = app(PromptRenderer::class)
        ->renderProductPrompt($topic, 'SGA.DB.001.08.26');

    expect($prompt)
        ->toContain('SGA.DB.001.08.26')
        ->toContain('08.03.26')
        ->toContain('DAILY STRATEGIC INTELLIGENCE ANALYTICS BRIEF')
        ->not->toContain('[PRIMARY_TOPIC]')
        ->not->toContain('[BYLINE]')
        ->not->toContain('[DOCUMENT_REF]')
        ->not->toContain('[DATE]')
        // The domain architecture is content and must survive rendering.
        ->toContain('SGA 12 Domain Architecture')
        // Pillars were renamed domains throughout; none may be left behind.
        ->not->toContain('Pillar');
});
