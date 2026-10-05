<?php

use App\Enums\ProductStatus;
use App\Enums\TaskStatus;
use App\Exceptions\Domain\ProductNotProofreadException;
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
        ->expectsOutputToContain('1 approved product(s) held back with no abstract')
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

it('refuses to approve a product no proofreader has submitted', function () use ($brief): void {
    // With the redaction pass off, the status map alone makes /approve legal
    // straight from in_proofreading — which would publish the model's raw
    // output. The proofread submission is the gate, not the status.
    config(['publishing.redaction' => false]);

    $product = Product::factory()->create([
        'abstract' => null,
        'body' => $brief,
        'status' => ProductStatus::AwaitingProofreading,
    ]);

    $task = GenerationTask::factory()->create([
        'product_id' => $product->id,
        'status' => TaskStatus::InProofreading,
        'proofread_at' => null,
    ]);

    $approver = User::factory()->create();
    $approver->assignRole(User::ADMIN);

    expect(fn () => app(GenerationPipeline::class)->approve($task, $approver))
        ->toThrow(ProductNotProofreadException::class);

    expect($product->refresh()->status)->toBe(ProductStatus::AwaitingProofreading);
});

it('derives the abstract on an approval that is allowed', function () use ($brief): void {
    $product = Product::factory()->create([
        'abstract' => null,
        'body' => $brief,
        'status' => ProductStatus::AwaitingRedaction,
    ]);

    $task = GenerationTask::factory()->create([
        'product_id' => $product->id,
        'status' => TaskStatus::AwaitingRedaction,
        'proofread_at' => now(),
    ]);

    $approver = User::factory()->create();
    $approver->assignRole(User::ADMIN);

    app(GenerationPipeline::class)->approve($task, $approver);

    expect($product->refresh()->abstract)->toContain('repricing of sovereign debt')
        ->and($product->status)->toBe(ProductStatus::Approved);
});

it('never takes an unproofread product live, however it was marked approved', function () use ($brief): void {
    $product = Product::factory()->approvedUnproofread()->create(['body' => $brief]);

    $this->artisan('products:release')
        ->expectsOutputToContain('Released 0 product(s).')
        ->expectsOutputToContain('held back because no proofreader has submitted the document')
        ->expectsOutputToContain($product->code)
        ->assertSuccessful();

    expect($product->refresh()->status)->toBe(ProductStatus::Approved);
});
