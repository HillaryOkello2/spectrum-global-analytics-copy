<?php

use App\Enums\ProductStatus;
use App\Enums\TaskStatus;
use App\Jobs\GenerateProductJob;
use App\Jobs\RunQaPromptJob;
use App\Models\Component;
use App\Models\GenerationTask;
use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

/**
 * Recovering from a run that failed for reasons outside the documents — the
 * missing `qa_verdict` column being the case this was written for.
 */
function failedTask(bool $withBody = true, ?Component $component = null): GenerationTask
{
    $component ??= Component::factory()->create(['code' => 'DB']);
    $topic = Topic::factory()->for($component)->create();

    // An empty body, not a null one: GenerationPipeline::createProductShell()
    // reserves the row with `body => ''` before the model is called, because
    // the document's own reference code has to exist before the prompt that
    // prints it is rendered. So a task that died before generation finished
    // leaves exactly this behind.
    $product = Product::factory()->for($component, 'component')->create([
        'status' => ProductStatus::Draft,
        'body' => $withBody ? str_repeat('word ', 400) : '',
    ]);

    return GenerationTask::factory()->create([
        'topic_id' => $topic->id,
        'product_id' => $product->id,
        'status' => TaskStatus::Failed,
        'queued_at' => now()->subHours(6),
        'completed_at' => now()->subHours(5),
        'last_error' => "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'qa_verdict'",
    ]);
}

function retryActor(): User
{
    $user = User::factory()->create(['email' => 'editor@example.com']);
    $user->assignRole(User::ADMIN);

    return $user;
}

it('resumes a task whose document already exists at the audit, not at generation', function (): void {
    Queue::fake();

    $task = failedTask();
    $body = $task->product->body;

    $this->artisan('generation:retry-failed', [
        '--actor' => retryActor()->email,
        '--reason' => 'Audit died on a missing column; the documents themselves are sound.',
    ])->expectsConfirmation('Requeue 1 task(s)?', 'yes')
        ->assertSuccessful();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::QaRunning)
        ->and($task->last_error)->toBeNull()
        ->and($task->completed_at)->toBeNull()
        // The expensive part: the written document is kept, not regenerated.
        ->and($task->product->body)->toBe($body);

    Queue::assertPushed(RunQaPromptJob::class, 1);
    Queue::assertNotPushed(GenerateProductJob::class);
});

it('starts a task over when there is no document to resume', function (): void {
    Queue::fake();

    $task = failedTask(withBody: false);

    $this->artisan('generation:retry-failed', [
        '--actor' => retryActor()->email,
        '--reason' => 'Generation died before the document was written.',
    ])->expectsConfirmation('Requeue 1 task(s)?', 'yes')
        ->assertSuccessful();

    expect($task->refresh()->status)->toBe(TaskStatus::Queued);

    Queue::assertPushed(GenerateProductJob::class, 1);
    Queue::assertNotPushed(RunQaPromptJob::class);
});

it('records who ordered the retry and from which stage', function (): void {
    Queue::fake();

    $task = failedTask();
    $editor = retryActor();

    $this->artisan('generation:retry-failed', [
        '--actor' => $editor->email,
        '--reason' => 'Audit died on a missing column; the documents themselves are sound.',
    ])->expectsConfirmation('Requeue 1 task(s)?', 'yes')
        ->assertSuccessful();

    $entry = Activity::where('subject_id', $task->id)
        ->where('subject_type', GenerationTask::class)
        ->latest('id')
        ->first();

    expect($entry->causer_id)->toBe($editor->id)
        ->and($entry->description)->toContain('requeued at the audit stage')
        ->and($entry->properties['resumedAt'])->toBe('qa');
});

it('changes nothing on a dry run', function (): void {
    Queue::fake();

    $task = failedTask();

    $this->artisan('generation:retry-failed', ['--dry-run' => true])->assertSuccessful();

    expect($task->refresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertNothingPushed();
});

it('refuses without a named actor who may proofread, or a usable reason', function (): void {
    Queue::fake();

    $task = failedTask();
    $editor = retryActor();

    $this->artisan('generation:retry-failed', [
        '--actor' => 'nobody@example.com',
        '--reason' => 'Audit died on a missing column.',
    ])->assertFailed();

    $this->artisan('generation:retry-failed', [
        '--actor' => $editor->email,
        '--reason' => 'oops',
    ])->assertFailed();

    expect($task->refresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertNothingPushed();
});

it('leaves tasks that did not fail alone', function (): void {
    Queue::fake();

    $held = GenerationTask::factory()->create(['status' => TaskStatus::QaFailed]);
    $onBoard = GenerationTask::factory()->create(['status' => TaskStatus::AwaitingProofreading]);

    $this->artisan('generation:retry-failed', [
        '--actor' => retryActor()->email,
        '--reason' => 'Retrying the failed overnight batch.',
    ])->assertSuccessful();

    // A QA hold is a verdict, not an error — generation:release-qa-failed is
    // the command for that one.
    expect($held->refresh()->status)->toBe(TaskStatus::QaFailed)
        ->and($onBoard->refresh()->status)->toBe(TaskStatus::AwaitingProofreading);

    Queue::assertNothingPushed();
});

it('limits the retry to one component when asked', function (): void {
    Queue::fake();

    $daily = Component::factory()->create(['code' => 'DB']);
    $weekly = Component::factory()->create(['code' => 'WH']);

    $dailyTask = failedTask(component: $daily);
    $weeklyTask = failedTask(component: $weekly);

    $this->artisan('generation:retry-failed', [
        '--actor' => retryActor()->email,
        '--reason' => 'Retrying the Daily Brief batch first.',
        '--component' => 'DB',
    ])->expectsConfirmation('Requeue 1 task(s)?', 'yes')
        ->assertSuccessful();

    expect($dailyTask->refresh()->status)->toBe(TaskStatus::QaRunning)
        ->and($weeklyTask->refresh()->status)->toBe(TaskStatus::Failed);
});

it('splits a batch by failure cause, so dead credentials are left alone', function (): void {
    Queue::fake();

    $transient = failedTask(component: Component::factory()->create(['code' => 'WH']));
    $transient->update(['last_error' => 'HTTP request returned status code 503: {"type":"overloaded"}']);

    $unauthorised = failedTask(component: Component::factory()->create(['code' => 'DB']));
    $unauthorised->update(['last_error' => 'HTTP request returned status code 401: {"type":"authentication_error"}']);

    $editor = retryActor();

    // Retrying a 401 fails again the moment it is sent, so a batch has to be
    // splittable along that line.
    $this->artisan('generation:retry-failed', [
        '--actor' => $editor->email,
        '--reason' => 'Provider was overloaded; retrying only the transient failures.',
        '--matching' => '503',
    ])->expectsConfirmation('Requeue 1 task(s)?', 'yes')
        ->assertSuccessful();

    expect($transient->refresh()->status)->toBe(TaskStatus::QaRunning)
        ->and($unauthorised->refresh()->status)->toBe(TaskStatus::Failed);

    // And the same split stated the other way round.
    $this->artisan('generation:retry-failed', [
        '--actor' => $editor->email,
        '--reason' => 'Retrying everything except the authentication failures.',
        '--excluding' => '401',
        '--dry-run' => true,
    ])->doesntExpectOutputToContain((string) $unauthorised->id)
        ->assertSuccessful();
});
