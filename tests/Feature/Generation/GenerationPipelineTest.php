<?php

use App\Enums\ProductStatus;
use App\Enums\TaskStatus;
use App\Models\GenerationTask;
use App\Models\Topic;
use App\Models\User;

function admin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

it('runs a queued topic through generation and QA onto the task board', function (): void {
    $topic = Topic::factory()->create();

    // sync queue + LLM_FAKE: the whole pipeline executes inline.
    $this->actingAs(admin())
        ->postJson(route('api.admin.topics.queue', $topic))
        ->assertCreated();

    $task = GenerationTask::firstOrFail();

    expect($task->status)->toBe(TaskStatus::AwaitingProofreading)
        ->and($task->product->status)->toBe(ProductStatus::AwaitingProofreading)
        ->and($task->product->body)->not->toBeEmpty()
        // The abstract is written by a proofreader — the LLM never supplies one.
        ->and($task->product->abstract)->toBeNull()
        ->and($task->product->code)->toStartWith('SGA.')
        ->and($task->qa_result)->not->toBeNull()
        ->and($topic->refresh()->last_generated_at)->not->toBeNull();
});

it('walks a task through proofreading, redaction and approval', function (): void {
    $topic = Topic::factory()->create();
    $reviewer = admin();

    $this->actingAs($reviewer)->postJson(route('api.admin.topics.queue', $topic));
    $task = GenerationTask::firstOrFail();

    $open = $this->actingAs($reviewer)
        ->postJson(route('api.admin.tasks.open', $task))
        ->assertOk()
        ->assertJsonPath('data.status', TaskStatus::InProofreading->value);

    // The proofreader must receive the FULL product body (not just the preview).
    $open->assertJsonPath('data.product.locked', false)
        ->assertJsonPath('data.product.body', $task->refresh()->product->body)
        ->assertJsonPath('data.topic.promptText', $task->topic->prompt_text)
        ->assertJsonPath('data.qaResult', $task->qa_result);

    expect($task->refresh()->proofreader_id)->toBe($reviewer->id)
        ->and($task->proofread_at)->not->toBeNull();

    // Stage 1: the abstract is authored here, alongside the corrected document.
    $this->actingAs($reviewer)
        ->postJson(route('api.admin.tasks.proofread', $task), [
            'abstract' => 'A human-written abstract.',
            'body' => 'The corrected document.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', TaskStatus::AwaitingRedaction->value);

    expect($task->refresh()->product->abstract)->toBe('A human-written abstract.')
        ->and($task->product->body)->toBe('The corrected document.')
        ->and($task->product->status)->toBe(ProductStatus::AwaitingRedaction);

    // Stage 2: the redaction is reviewed separately and approves the product.
    $this->actingAs($reviewer)
        ->postJson(route('api.admin.tasks.redact', $task), ['redacted_body' => 'The redacted document.'])
        ->assertOk()
        ->assertJsonPath('data.status', TaskStatus::Approved->value);

    expect($task->refresh()->redactor_id)->toBe($reviewer->id)
        ->and($task->redacted_at)->not->toBeNull()
        ->and($task->product->redacted_body)->toBe('The redacted document.')
        ->and($task->product->redaction_approved)->toBeTrue()
        ->and($task->product->status)->toBe(ProductStatus::Approved)
        ->and($task->product->approved_at)->not->toBeNull()
        // Approval is not publication — the release queue does that.
        ->and($task->product->published_at)->toBeNull();
});

it('requires an abstract when submitting a proofread', function (): void {
    $topic = Topic::factory()->create();
    $reviewer = admin();

    $this->actingAs($reviewer)->postJson(route('api.admin.topics.queue', $topic));
    $task = GenerationTask::firstOrFail();
    $this->actingAs($reviewer)->postJson(route('api.admin.tasks.open', $task));

    $this->actingAs($reviewer)
        ->postJson(route('api.admin.tasks.proofread', $task), ['body' => 'Only the document.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('abstract');
});

it('will not skip the redaction stage', function (): void {
    $topic = Topic::factory()->create();
    $reviewer = admin();

    $this->actingAs($reviewer)->postJson(route('api.admin.topics.queue', $topic));
    $task = GenerationTask::firstOrFail();
    $this->actingAs($reviewer)->postJson(route('api.admin.tasks.open', $task));

    // Redaction submitted before the document was proofread.
    $this->actingAs($reviewer)
        ->postJson(route('api.admin.tasks.redact', $task), ['redacted_body' => 'Too early.'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'invalid_task_transition');
});

it('shows the full task detail for review without changing its status', function (): void {
    $topic = Topic::factory()->create();
    $reviewer = admin();

    $this->actingAs($reviewer)->postJson(route('api.admin.topics.queue', $topic));
    $task = GenerationTask::firstOrFail();

    $this->actingAs($reviewer)
        ->getJson(route('api.admin.tasks.show', $task))
        ->assertOk()
        ->assertJsonPath('data.product.locked', false)
        ->assertJsonPath('data.product.body', $task->product->body)
        ->assertJsonPath('data.llmProvider.name', $task->llmProvider->name);

    // Read-only: still awaiting proofreading, no proofreader claimed.
    expect($task->refresh()->status)->toBe(TaskStatus::AwaitingProofreading)
        ->and($task->proofreader_id)->toBeNull();
});

it('rejects with a note and returns the task to the board', function (): void {
    $topic = Topic::factory()->create();
    $reviewer = admin();

    $this->actingAs($reviewer)->postJson(route('api.admin.topics.queue', $topic));
    $task = GenerationTask::firstOrFail();

    $this->actingAs($reviewer)->postJson(route('api.admin.tasks.open', $task));

    $this->actingAs($reviewer)
        ->postJson(route('api.admin.tasks.reject', $task), ['note' => 'Sources need verification.'])
        ->assertOk()
        ->assertJsonPath('data.status', TaskStatus::Rejected->value)
        ->assertJsonPath('data.rejectionNote', 'Sources need verification.');

    expect($task->refresh()->product->status)->toBe(ProductStatus::Rejected);
});

it('blocks invalid task transitions', function (): void {
    $topic = Topic::factory()->create();
    $reviewer = admin();

    $this->actingAs($reviewer)->postJson(route('api.admin.topics.queue', $topic));
    $task = GenerationTask::firstOrFail();

    // Approve without opening proofreading first → invalid transition.
    $this->actingAs($reviewer)
        ->postJson(route('api.admin.tasks.approve', $task))
        ->assertStatus(409)
        ->assertJsonPath('code', 'invalid_task_transition');
});

it('keeps admin endpoints off-limits to subscribers', function (): void {
    $subscriber = User::factory()->create();
    $subscriber->assignRole('subscriber');

    $this->actingAs($subscriber)
        ->getJson(route('api.admin.tasks.index'))
        ->assertForbidden();
});
