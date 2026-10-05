<?php

use App\Enums\Frequency;
use App\Exceptions\TopicGenerationFailedException;
use App\Jobs\DispatchDueTopicsJob;
use App\Jobs\GenerateProductJob;
use App\Jobs\GenerateTopicJob;
use App\Models\Component;
use App\Models\Topic;
use App\Services\Generation\GenerationPipeline;
use App\Services\Generation\TopicGenerator;
use App\Services\Generation\TopicVariableFiller;
use Illuminate\Support\Facades\Queue;

it('commissions a topic from the component prompt', function (): void {
    $component = Component::factory()->selfCommissioning()->create();

    $topic = app(TopicGenerator::class)->generate($component);

    expect($topic->source)->toBe(Topic::SOURCE_AUTO)
        ->and($topic->variables)->toHaveKeys(['PRIMARY_TOPIC', 'BYLINE'])
        ->and($topic->variables['PRIMARY_TOPIC'])->not->toBeEmpty()
        // An auto topic carries variables, never its own prompt.
        ->and($topic->prompt_text)->toBeNull()
        ->and($topic->frequency)->toBe(Frequency::Daily);
});

it('names the topic from the component title template', function (): void {
    $this->travelTo('2026-08-03');

    $component = Component::factory()->selfCommissioning()->create();

    expect(app(TopicGenerator::class)->generate($component)->title)
        ->toBe('TEST PULSE BRIEF — 08.03.26');
});

it('refuses a component with no prompt template', function (): void {
    $component = Component::factory()->create(['topic_prompt' => null, 'prompt_template' => null]);

    expect(fn () => app(TopicGenerator::class)->generate($component))
        ->toThrow(TopicGenerationFailedException::class);
});

it('rejects a reply missing a required key', function (): void {
    // The stub echoes back whatever schema the prompt asks for, so asking for a
    // key the component does not declare is how we simulate a bad reply.
    $component = Component::factory()->selfCommissioning()->create([
        'variables' => ['PRIMARY_TOPIC', 'BYLINE', 'NEVER_RETURNED'],
    ]);

    expect(fn () => app(TopicGenerator::class)->generate($component))
        ->toThrow(TopicGenerationFailedException::class);
});

it('commissions only components that are due', function (): void {
    Queue::fake();

    $daily = Component::factory()->selfCommissioning()->create();
    $monthly = Component::factory()->selfCommissioning(Frequency::Monthly)->create();

    // Both already have this period's edition.
    Topic::factory()->for($daily)->auto()->create(['created_at' => now()->subHours(2)]);
    Topic::factory()->for($monthly)->auto()->create(['created_at' => now()->subDays(2)]);

    app(DispatchDueTopicsJob::class)->handle(app(GenerationPipeline::class));

    Queue::assertNotPushed(GenerateTopicJob::class);

    // A day later the daily is due again; the monthly is not.
    $this->travelTo(now()->addDay()->addHour());
    app(DispatchDueTopicsJob::class)->handle(app(GenerationPipeline::class));

    Queue::assertPushed(GenerateTopicJob::class, 1);
    Queue::assertPushed(fn (GenerateTopicJob $job) => $job->component->is($daily));
});

it('does not re-generate previous auto editions on the next tick', function (): void {
    Queue::fake();

    $component = Component::factory()->selfCommissioning()->create();

    // Yesterday's edition, already generated. The second pass must ignore it —
    // otherwise every past edition would regenerate daily, forever.
    Topic::factory()->for($component)->auto()->create([
        'created_at' => now()->subDay(),
        'last_generated_at' => now()->subDay(),
    ]);

    app(DispatchDueTopicsJob::class)->handle(app(GenerationPipeline::class));

    Queue::assertNotPushed(GenerateProductJob::class);
    // A fresh edition is commissioned instead.
    Queue::assertPushed(GenerateTopicJob::class, 1);
});

it('still dispatches due manual topics', function (): void {
    Queue::fake();

    $topic = Topic::factory()->create([
        'frequency' => Frequency::Daily,
        'last_generated_at' => now()->subDays(2),
    ]);

    app(DispatchDueTopicsJob::class)->handle(app(GenerationPipeline::class));

    Queue::assertPushed(GenerateProductJob::class, 1);
    expect($topic->refresh()->last_generated_at->isToday())->toBeTrue();
});

it('queues generation on the component queue', function (): void {
    Queue::fake();

    $component = Component::factory()->create(['queue_name' => 'llm-rp']);
    $topic = Topic::factory()->for($component)->create();

    app(GenerationPipeline::class)->queueTopic($topic);

    Queue::assertPushed(fn (GenerateProductJob $job) => $job->queue === 'llm-rp');
});

it('accepts a topic with variables and no prompt of its own', function (): void {
    $component = Component::factory()->create();

    $this->actingAs(admin())
        ->postJson(route('api.admin.topics.store'), [
            'title' => 'Sovereign compute capacity',
            'component' => $component->public_id,
            'frequency' => Frequency::Monthly->value,
            'variables' => ['PRIMARY_TOPIC' => 'The fragmentation of semiconductor supply'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.source', Topic::SOURCE_MANUAL)
        ->assertJsonPath('data.promptText', null)
        ->assertJsonPath('data.variables.PRIMARY_TOPIC', 'The fragmentation of semiconductor supply');
});

it('accepts a topic filed with nothing but a title and a component', function (): void {
    // All an editor should have to decide. The cadence comes from the
    // component and the variables are filled at generation time.
    $component = Component::factory()->create([
        'generation_frequency' => Frequency::Weekly,
        'variables' => ['PRIMARY_TOPIC', 'BYLINE'],
    ]);

    $this->actingAs(admin())
        ->postJson(route('api.admin.topics.store'), [
            'title' => 'The Quiet Repricing of Sovereign Debt',
            'component' => $component->public_id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.source', Topic::SOURCE_MANUAL)
        ->assertJsonPath('data.frequency', Frequency::Weekly->value)
        ->assertJsonPath('data.title', 'The Quiet Repricing of Sovereign Debt');
});

it('falls back to a monthly cadence for a component the client gave none', function (): void {
    $component = Component::factory()->create(['generation_frequency' => null]);

    $this->actingAs(admin())
        ->postJson(route('api.admin.topics.store'), [
            'title' => 'A one-off study',
            'component' => $component->public_id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.frequency', Frequency::Monthly->value);
});

it('still needs a prompt for a component that has no template of its own', function (): void {
    // Nothing to render, so nothing to send the model.
    $component = Component::factory()->create(['prompt_template' => null]);

    $this->actingAs(admin())
        ->postJson(route('api.admin.topics.store'), [
            'title' => 'Nothing to say',
            'component' => $component->public_id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('prompt_text');
});

it('still accepts a hand-written prompt', function (): void {
    $component = Component::factory()->create();

    $this->actingAs(admin())
        ->postJson(route('api.admin.topics.store'), [
            'title' => 'A one-off',
            'component' => $component->public_id,
            'frequency' => Frequency::Monthly->value,
            'prompt_text' => 'Write about the thing.',
            'qa_prompt_text' => 'Check the thing.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.promptText', 'Write about the thing.');
});

it('fills the prompt variables an editor did not type', function (): void {
    $component = Component::factory()->create([
        'variables' => ['PRIMARY_TOPIC', 'BYLINE'],
        'fixed_variables' => ['DOCUMENT_TITLE' => 'WEEKLY HIGHLIGHTS'],
    ]);

    $topic = Topic::factory()->for($component)->create([
        'title' => 'The Quiet Repricing of Sovereign Debt',
        'prompt_text' => null,
        'variables' => null,
    ]);

    app(TopicVariableFiller::class)->fill($topic);

    $filled = $topic->refresh()->variables;

    // The title answers the subject slot; the model answers the rest; what the
    // component fixes for the whole series is never asked for.
    expect($filled['PRIMARY_TOPIC'])->toBe('The Quiet Repricing of Sovereign Debt')
        ->and($filled['BYLINE'])->toBeString()->not->toBeEmpty()
        ->and($filled)->not->toHaveKey('DOCUMENT_TITLE');
});

it('leaves a topic that carries its own prompt alone', function (): void {
    $topic = Topic::factory()->create(['prompt_text' => 'Write about the thing.', 'variables' => null]);

    app(TopicVariableFiller::class)->fill($topic);

    expect($topic->refresh()->variables)->toBeNull();
});
