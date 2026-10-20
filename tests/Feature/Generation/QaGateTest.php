<?php

use App\Enums\ProductStatus;
use App\Enums\QaVerdict;
use App\Enums\TaskStatus;
use App\Models\Component;
use App\Models\GenerationTask;
use App\Models\Topic;
use App\Models\User;
use App\Services\Generation\QaReportParser;
use App\Services\Generation\RevisionPromptBuilder;
use Spatie\Activitylog\Models\Activity;

function reviewer(): User
{
    $user = User::factory()->create();
    $user->assignRole('admin');

    return $user;
}

/** Queue a topic and run the whole pipeline inline (sync queue + LLM_FAKE). */
function generate(): GenerationTask
{
    $topic = Topic::factory()->create();

    test()->actingAs(reviewer())
        ->postJson(route('api.admin.topics.queue', $topic))
        ->assertCreated();

    return GenerationTask::firstOrFail();
}

// --- Reading the verdict -------------------------------------------------

it('reads a GREEN LIGHT verdict', function (): void {
    $report = app(QaReportParser::class)->parse(implode("\n", [
        '1. STRATEGIC-ONLY MANDATE VERIFICATION: PASS',
        '======================================',
        'FINAL VERDICT: GREEN LIGHT - APPROVED FOR CLIENT RELEASE',
    ]));

    expect($report->verdict)->toBe(QaVerdict::Green)
        ->and($report->failedSections)->toBe([])
        ->and($report->isPass())->toBeTrue();
});

it('reads a RED LIGHT verdict with its failed sections and corrections', function (): void {
    $report = app(QaReportParser::class)->parse(implode("\n", [
        '1. STRATEGIC-ONLY MANDATE VERIFICATION: FAIL',
        '2. STRUCTURAL INTEGRITY AUDIT: PASS',
        '4. FACTUAL RIGOR & ANTI-HALLUCINATION AUDIT: FAIL',
        '======================================',
        'FINAL VERDICT: RED LIGHT - REJECTED FOR RE-RUN',
        'RE-RUN INSTRUCTIONS (IF RED LIGHT): Cut the tactical inventory in paragraph 3.',
        '======================================',
    ]));

    expect($report->verdict)->toBe(QaVerdict::Red)
        ->and($report->failedSections)->toBe([
            '1. STRATEGIC-ONLY MANDATE VERIFICATION',
            '4. FACTUAL RIGOR & ANTI-HALLUCINATION AUDIT',
        ])
        ->and($report->rerunInstructions)->toBe('Cut the tactical inventory in paragraph 3.');
});

it('is not fooled by an auditor that echoes the blank template', function (): void {
    // The protocol's own template carries both options on one line. A model
    // that reproduces it before filling it in must not read as a pass.
    $report = app(QaReportParser::class)->parse(implode("\n", [
        'FINAL VERDICT: [GREEN LIGHT - APPROVED FOR CLIENT RELEASE / RED LIGHT - REJECTED FOR RE-RUN]',
        '',
        '1. STRATEGIC-ONLY MANDATE VERIFICATION: FAIL',
    ]));

    expect($report->verdict)->toBe(QaVerdict::Red);
});

it('falls back to the section results when there is no verdict line', function (): void {
    $parser = app(QaReportParser::class);

    expect($parser->parse("1. STRATEGIC-ONLY MANDATE: PASS\n2. STRUCTURAL INTEGRITY: PASS")->verdict)
        ->toBe(QaVerdict::Green)
        ->and($parser->parse('1. STRATEGIC-ONLY MANDATE: FAIL')->verdict)->toBe(QaVerdict::Red);
});

it('reads the section lines however the model marked them up', function (): void {
    // All three forms appear across the 53 real audit reports in
    // storage/app/llm-test: plain, bolded, and as markdown headings.
    $report = app(QaReportParser::class)->parse(implode("\n", [
        '### 1. STRATEGIC-ONLY MANDATE VERIFICATION: **FAIL**',
        '## 2. STRUCTURAL INTEGRITY AUDIT: PASS',
        '**3. SYNTAX & LINGUISTIC PRECISION AUDIT:** [FAIL]',
        '## FINAL VERDICT: **RED LIGHT — REJECTED FOR RE-RUN**',
    ]));

    expect($report->verdict)->toBe(QaVerdict::Red)
        ->and($report->failedSections)->toBe([
            '1. STRATEGIC-ONLY MANDATE VERIFICATION',
            '3. SYNTAX & LINGUISTIC PRECISION AUDIT',
        ]);
});

it('takes the verdict line over section lines that disagree with it', function (): void {
    // A real report (verify2/WP-qa-cross-anthropic): every section marked
    // PASS and the verdict RED LIGHT. The verdict is the auditor's answer.
    $report = app(QaReportParser::class)->parse(implode("\n", [
        '1. STRATEGIC-ONLY MANDATE VERIFICATION: PASS',
        '2. STRUCTURAL INTEGRITY AUDIT: PASS',
        'FINAL VERDICT: RED LIGHT - REJECTED FOR RE-RUN',
    ]));

    expect($report->verdict)->toBe(QaVerdict::Red)
        ->and($report->failedSections)->toBe([]);
});

it('calls an unreadable audit unknown rather than guessing', function (): void {
    expect(app(QaReportParser::class)->parse('I was unable to review this document.')->verdict)
        ->toBe(QaVerdict::Unknown);
});

// --- The gate ------------------------------------------------------------

it('sends a passing document to the proofreader as before', function (): void {
    $task = generate();

    expect($task->status)->toBe(TaskStatus::AwaitingProofreading)
        ->and($task->qa_verdict)->toBe(QaVerdict::Green)
        ->and($task->qa_round)->toBe(0)
        ->and($task->product->status)->toBe(ProductStatus::AwaitingProofreading)
        ->and($task->qaReviews)->toHaveCount(1);
});

it('rewrites a failed document and lets the rewrite through', function (): void {
    // The stub fails the first draft and passes the rewrite, which is the
    // whole loop: audit → revise → re-audit.
    config(['llm.fake_qa_verdict' => 'first_red']);

    $task = generate();

    expect($task->status)->toBe(TaskStatus::AwaitingProofreading)
        ->and($task->qa_round)->toBe(1)
        ->and($task->qa_verdict)->toBe(QaVerdict::Green)
        ->and($task->product->status)->toBe(ProductStatus::AwaitingProofreading);

    $reviews = $task->qaReviews;

    expect($reviews)->toHaveCount(2)
        ->and($reviews[0]->verdict)->toBe(QaVerdict::Red)
        ->and($reviews[0]->round)->toBe(0)
        ->and($reviews[0]->failed_sections)->not->toBeEmpty()
        ->and($reviews[0]->rerun_instructions)->not->toBeNull()
        ->and($reviews[1]->verdict)->toBe(QaVerdict::Green)
        ->and($reviews[1]->round)->toBe(1);
});

it('gives up after the configured number of rewrites and keeps the document out of the vault', function (): void {
    config(['llm.fake_qa_verdict' => 'red', 'qa.max_revisions' => 2]);

    $task = generate();

    // Each rewrite answers the audit that rejected *it*, not the first one.
    expect($task->latestQaReview()->round)->toBe(2);

    expect($task->status)->toBe(TaskStatus::QaFailed)
        ->and($task->qa_verdict)->toBe(QaVerdict::Red)
        ->and($task->qa_round)->toBe(2)
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->last_error)->toContain('Failed quality control')
        ->and($task->product->status)->toBe(ProductStatus::QaFailed)
        // The first audit plus one per rewrite.
        ->and($task->qaReviews)->toHaveCount(3);
});

it('never puts a failed document in front of a proofreader', function (): void {
    config(['llm.fake_qa_verdict' => 'red']);

    $task = generate();

    // The board's proofreading actions refuse it: `qa_failed` is terminal, so
    // nobody can pick a rejected document off the board and walk it through as
    // though it had passed.
    $this->actingAs(reviewer())
        ->postJson(route('api.admin.tasks.open', $task))
        ->assertStatus(409);

    expect($task->refresh()->status)->toBe(TaskStatus::QaFailed)
        ->and($task->proofreader_id)->toBeNull();
});

it('leaves a failed document out of the vault listing but reachable with an explicit filter', function (): void {
    config(['llm.fake_qa_verdict' => 'red']);

    $task = generate();
    $component = $task->product->component;
    $admin = reviewer();

    $listed = $this->actingAs($admin)
        ->getJson(route('api.admin.vault.components.products.index', $component))
        ->assertOk()
        ->json('data');

    expect($listed)->toBe([]);

    $filtered = $this->actingAs($admin)
        ->getJson(route('api.admin.vault.components.products.index', $component).'?status=qa_failed')
        ->assertOk()
        ->json('data');

    expect($filtered)->toHaveCount(1)
        ->and($filtered[0]['publicId'])->toBe($task->product->public_id);

    // And the component's own count matches what the drill-down lists.
    $counts = collect($this->actingAs($admin)
        ->getJson(route('api.admin.vault.components.index'))
        ->json('data'))
        ->firstWhere('publicId', $component->public_id);

    expect($counts['productsCount'])->toBe(0);
});

it('lets a proofreader overrule the verdict, with a reason on the record', function (): void {
    config(['llm.fake_qa_verdict' => 'red']);

    $task = generate();
    $admin = reviewer();

    $this->actingAs($admin)
        ->postJson(route('api.admin.tasks.qa-override', $task), ['reason' => 'short'])
        ->assertStatus(422);

    $this->actingAs($admin)
        ->postJson(route('api.admin.tasks.qa-override', $task), [
            'reason' => 'Audit faulted a figure the document does not contain; checked against the source.',
        ])
        ->assertOk();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::InProofreading)
        ->and($task->proofreader_id)->toBe($admin->id)
        ->and($task->product->status)->toBe(ProductStatus::InProofreading);

    $entry = Activity::where('description', 'QA failure overridden; document taken into proofreading')->sole();

    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->properties['reason'])->toContain('checked against the source')
        ->and($entry->properties['changes'][0]['from'])->toBe('qa_failed')
        ->and($entry->properties['changes'][0]['to'])->toBe('in_proofreading');
});

it('refuses an override on a task the gate did not stop', function (): void {
    $task = generate();

    $this->actingAs(reviewer())
        ->postJson(route('api.admin.tasks.qa-override', $task), [
            'reason' => 'This task passed its audit and has no failure to override.',
        ])
        ->assertStatus(409);

    expect($task->refresh()->status)->toBe(TaskStatus::AwaitingProofreading);
});

it('can be switched off, leaving the audit advisory', function (): void {
    config(['llm.fake_qa_verdict' => 'red', 'qa.enforce' => false]);

    $task = generate();

    expect($task->status)->toBe(TaskStatus::AwaitingProofreading)
        // Still parsed and still stored — the proofreader reads the verdict,
        // nothing acts on it.
        ->and($task->qa_verdict)->toBe(QaVerdict::Red)
        ->and($task->qaReviews)->toHaveCount(1);
});

// --- What goes back to the model ----------------------------------------

it('sends the specification, the rejected draft and the audit back together', function (): void {
    config(['llm.fake_qa_verdict' => 'red', 'qa.max_revisions' => 1]);

    $task = generate();
    $review = $task->qaReviews->first();

    // Rebuild the prompt the revision used, against the first audit.
    $prompt = app(RevisionPromptBuilder::class)->build($task->refresh(), $review);

    expect($prompt)
        ->toContain(RevisionPromptBuilder::MARKER)
        ->toContain('ORIGINAL SPECIFICATION')
        ->toContain('PREVIOUS DRAFT (REJECTED)')
        ->toContain('QUALITY CONTROL AUDIT REPORT')
        ->toContain($review->rerun_instructions)
        // The specification the audit judged the document against, so the
        // rewrite does not fix the cited faults and break something else.
        ->toContain($task->topic->prompt_text)
        // And the instruction that keeps the document's reference code, which
        // the audit checks and a fresh generation would re-allocate.
        ->toContain('keep the same document reference code');
});

it('records every audit against the task for the proofreader to read', function (): void {
    config(['llm.fake_qa_verdict' => 'first_red']);

    $task = generate();

    $detail = $this->actingAs(reviewer())
        ->getJson(route('api.admin.tasks.show', $task))
        ->assertOk()
        ->json('data');

    expect($detail['qaVerdict'])->toBe('green')
        ->and($detail['qaRound'])->toBe(1)
        ->and($detail['qaReviews'])->toHaveCount(2)
        ->and($detail['qaReviews'][0]['verdict'])->toBe('red')
        ->and($detail['qaReviews'][0]['failedSections'])->not->toBeEmpty()
        ->and($detail['qaReviews'][0]['report'])->toContain('RED LIGHT');
});
