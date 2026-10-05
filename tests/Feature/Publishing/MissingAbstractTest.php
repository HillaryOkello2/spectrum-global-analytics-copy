<?php

use App\Enums\ProductStatus;
use App\Enums\TaskStatus;
use App\Models\GenerationTask;
use App\Models\Product;
use App\Models\User;
use App\Services\Generation\GenerationPipeline;

function approvedWithoutAbstract(string $body): Product
{
    return Product::factory()->approved()->create(['abstract' => null, 'body' => $body]);
}

$brief = <<<'MD'
# SPECTRUM GLOBAL ANALYTICS

## PART I — ABSTRACT PAPER

### 1. Executive Summary

The repricing of sovereign debt is proceeding faster than the issuance calendars of the
affected states can absorb, and the resulting refinancing gap is structural rather than cyclical.

Three creditor blocs now price the same paper differently, which fragments the market that
sovereign borrowers have relied upon as a single pool of demand since the 1990s.

2. Analytical Assessment

Further sections follow.
MD;

it('holds an approved product back when it has no abstract, and says so', function () use ($brief): void {
    approvedWithoutAbstract($brief);

    // This is the exact symptom: a full board and nothing released.
    $this->artisan('products:release')
        ->expectsOutputToContain('Released 0 product(s).')
        ->expectsOutputToContain('1 approved product(s) are held back because they have no abstract.')
        ->assertSuccessful();
});

it('derives the missing abstract from the body, which then releases', function () use ($brief): void {
    $product = approvedWithoutAbstract($brief);

    $this->artisan('products:backfill-abstracts')->assertSuccessful();

    expect($product->refresh()->abstract)->toContain('repricing of sovereign debt');

    $this->artisan('products:release')->assertSuccessful();

    expect($product->refresh()->status)->toBe(ProductStatus::Published)
        ->and($product->published_at)->not->toBeNull();
});

it('changes nothing on a dry run', function () use ($brief): void {
    $product = approvedWithoutAbstract($brief);

    $this->artisan('products:backfill-abstracts --dry-run')->assertSuccessful();

    expect($product->refresh()->abstract)->toBeNull();
});

it('reports a body it cannot lift an abstract from rather than guessing', function (): void {
    // Headings and table rows only — nothing that reads as prose.
    $product = approvedWithoutAbstract("# Title\n\n## 1. Heading\n\n| a | b |\n| - | - |\n");

    $this->artisan('products:backfill-abstracts')
        ->expectsOutputToContain('needs a human')
        ->assertSuccessful();

    expect($product->refresh()->abstract)->toBeNull();
});

it('derives an abstract when a task is approved without a proofread submission', function () use ($brief): void {
    // The route into this bug: with the redaction pass off, /approve is legal
    // straight from in_proofreading, and it never touched the abstract — so an
    // admin who hits Approve instead of submitting the proofread stranded the
    // product in the release queue for good.
    config(['publishing.redaction' => false]);

    $product = Product::factory()->create([
        'abstract' => null,
        'body' => $brief,
        'status' => ProductStatus::AwaitingProofreading,
    ]);

    $task = GenerationTask::factory()->create([
        'product_id' => $product->id,
        'status' => TaskStatus::InProofreading,
    ]);

    $approver = User::factory()->create();
    $approver->assignRole(User::ADMIN);

    app(GenerationPipeline::class)->approve($task, $approver);

    expect($product->refresh()->abstract)->toContain('repricing of sovereign debt')
        ->and($product->status)->toBe(ProductStatus::Approved);
});
