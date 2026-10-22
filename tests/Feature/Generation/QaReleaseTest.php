<?php

use App\Enums\ProductStatus;
use App\Enums\QaVerdict;
use App\Enums\TaskStatus;
use App\Models\Component;
use App\Models\GenerationTask;
use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Turning the gate off stops it holding anything new. Releasing is what brings
 * back the documents it already holds — they carry `qa_failed` on their own
 * rows, so no config change can reach them.
 */
function heldTask(?Component $component = null): GenerationTask
{
    $component ??= Component::factory()->create(['code' => 'DB']);
    $topic = Topic::factory()->for($component)->create();

    $product = Product::factory()->for($component, 'component')->create([
        'status' => ProductStatus::QaFailed,
    ]);

    return GenerationTask::factory()->create([
        'topic_id' => $topic->id,
        'product_id' => $product->id,
        'status' => TaskStatus::QaFailed,
        'qa_verdict' => QaVerdict::Red,
        'qa_round' => 2,
        'queued_at' => now()->subHours(6),
        'completed_at' => now()->subHours(5),
        'last_error' => 'Failed quality control after 2 revision(s): sections 1, 2',
    ]);
}

function proofreader(): User
{
    $user = User::factory()->create(['email' => 'editor@example.com']);
    $user->assignRole(User::ADMIN);

    return $user;
}

it('returns a held document to the board, unassigned', function (): void {
    $task = heldTask();
    $editor = proofreader();

    $this->artisan('generation:release-qa-failed', [
        '--actor' => $editor->email,
        '--reason' => 'Auditor mis-calibrated overnight; releasing the batch for human review.',
    ])->expectsConfirmation('Release 1 task(s) to the proofreading board?', 'yes')
        ->assertSuccessful();

    $task->refresh();

    // On the board, and claimed by nobody — unlike a per-document override,
    // which hands it to the person who overruled the verdict.
    expect($task->status)->toBe(TaskStatus::AwaitingProofreading)
        ->and($task->proofreader_id)->toBeNull()
        ->and($task->completed_at)->toBeNull()
        ->and($task->product->status)->toBe(ProductStatus::AwaitingProofreading);
});

it('keeps the verdict and the report on the released document', function (): void {
    $task = heldTask();
    $editor = proofreader();

    $this->artisan('generation:release-qa-failed', [
        '--actor' => $editor->email,
        '--reason' => 'Releasing the overnight batch for human review.',
    ])->expectsConfirmation('Release 1 task(s) to the proofreading board?', 'yes')
        ->assertSuccessful();

    // Released, not absolved: the proofreader must still see what the auditor
    // objected to.
    expect($task->refresh()->qa_verdict)->toBe(QaVerdict::Red)
        ->and($task->qa_round)->toBe(2);
});

it('records who ordered the release and why', function (): void {
    $task = heldTask();
    $editor = proofreader();

    $this->artisan('generation:release-qa-failed', [
        '--actor' => $editor->email,
        '--reason' => 'Auditor mis-calibrated overnight; releasing the batch for human review.',
    ])->expectsConfirmation('Release 1 task(s) to the proofreading board?', 'yes')
        ->assertSuccessful();

    $entry = Activity::where('subject_id', $task->id)
        ->where('subject_type', GenerationTask::class)
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($editor->id)
        ->and($entry->description)->toContain('QA hold released')
        ->and($entry->properties['reason'])->toContain('mis-calibrated');
});

it('changes nothing on a dry run', function (): void {
    $task = heldTask();

    $this->artisan('generation:release-qa-failed', ['--dry-run' => true])
        ->assertSuccessful();

    expect($task->refresh()->status)->toBe(TaskStatus::QaFailed);
});

it('refuses without a named actor who may proofread', function (): void {
    $task = heldTask();

    $subscriber = User::factory()->create(['email' => 'reader@example.com']);
    $subscriber->assignRole(User::SUBSCRIBER);

    $this->artisan('generation:release-qa-failed', [
        '--actor' => $subscriber->email,
        '--reason' => 'Releasing the overnight batch for human review.',
    ])->assertFailed();

    $this->artisan('generation:release-qa-failed', [
        '--actor' => 'nobody@example.com',
        '--reason' => 'Releasing the overnight batch for human review.',
    ])->assertFailed();

    expect($task->refresh()->status)->toBe(TaskStatus::QaFailed);
});

it('refuses a reason too thin to be worth recording', function (): void {
    $task = heldTask();
    $editor = proofreader();

    $this->artisan('generation:release-qa-failed', [
        '--actor' => $editor->email,
        '--reason' => 'nope',
    ])->assertFailed();

    expect($task->refresh()->status)->toBe(TaskStatus::QaFailed);
});

it('limits the release to one component when asked', function (): void {
    $daily = Component::factory()->create(['code' => 'DB']);
    $weekly = Component::factory()->create(['code' => 'WH']);

    $dailyTask = heldTask($daily);
    $weeklyTask = heldTask($weekly);
    $editor = proofreader();

    $this->artisan('generation:release-qa-failed', [
        '--actor' => $editor->email,
        '--reason' => 'Only the Daily Brief auditor is mis-calibrated.',
        '--component' => 'DB',
    ])->expectsConfirmation('Release 1 task(s) to the proofreading board?', 'yes')
        ->assertSuccessful();

    expect($dailyTask->refresh()->status)->toBe(TaskStatus::AwaitingProofreading)
        ->and($weeklyTask->refresh()->status)->toBe(TaskStatus::QaFailed);
});

it('never touches a task the gate is not holding', function (): void {
    $editor = proofreader();

    $inProgress = GenerationTask::factory()->create(['status' => TaskStatus::Revising]);

    $this->artisan('generation:release-qa-failed', [
        '--actor' => $editor->email,
        '--reason' => 'Releasing the overnight batch for human review.',
    ])->assertSuccessful();

    // A revision in flight is not a hold — it is still being worked on.
    expect($inProgress->refresh()->status)->toBe(TaskStatus::Revising);
});

it('releases a held document straight onto the board the API lists', function (): void {
    $task = heldTask();
    $editor = proofreader();

    $this->artisan('generation:release-qa-failed', [
        '--actor' => $editor->email,
        '--reason' => 'Auditor mis-calibrated overnight; releasing the batch for human review.',
    ])->expectsConfirmation('Release 1 task(s) to the proofreading board?', 'yes')
        ->assertSuccessful();

    // The whole point: it shows up where the proofreaders look.
    $this->actingAs($editor)
        ->getJson(route('api.admin.tasks.index', ['status' => TaskStatus::AwaitingProofreading->value]))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
