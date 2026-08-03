<?php

use App\Enums\ProductStatus;
use App\Enums\TaskStatus;
use App\Models\GenerationTask;
use App\Models\Product;
use App\Services\Publishing\ReleaseService;

it('publishes approved products oldest-approval-first', function (): void {
    $second = Product::factory()->approved()->create(['approved_at' => now()->subHour()]);
    $first = Product::factory()->approved()->create(['approved_at' => now()->subDay()]);
    $third = Product::factory()->approved()->create(['approved_at' => now()]);

    $released = app(ReleaseService::class)->releaseBatch(2);

    expect($released->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($first->refresh()->status)->toBe(ProductStatus::Published)
        ->and($first->published_at)->not->toBeNull()
        // Beyond the batch, so still queued.
        ->and($third->refresh()->status)->toBe(ProductStatus::Approved)
        ->and($third->published_at)->toBeNull();
});

it('honours the configured batch size', function (): void {
    Product::factory()->approved()->count(5)->create();

    config(['publishing.release_batch' => 2]);

    expect(app(ReleaseService::class)->releaseBatch())->toHaveCount(2)
        ->and(Product::where('status', ProductStatus::Published)->count())->toBe(2);
});

it('refuses to release a product with no abstract', function (): void {
    // The abstract is the public preview and is written by a human — a product
    // without one must never reach the catalogue.
    Product::factory()->approved()->withoutAbstract()->create();

    expect(app(ReleaseService::class)->releaseBatch())->toBeEmpty()
        ->and(Product::where('status', ProductStatus::Published)->count())->toBe(0);
});

it('leaves unapproved products alone', function (): void {
    Product::factory()->create(); // draft
    Product::factory()->create(['status' => ProductStatus::AwaitingProofreading]);

    expect(app(ReleaseService::class)->releaseBatch())->toBeEmpty();
});

it('completes the generation task when its product is released', function (): void {
    $product = Product::factory()->approved()->create();
    $task = GenerationTask::factory()->create([
        'product_id' => $product->id,
        'status' => TaskStatus::Approved,
    ]);

    app(ReleaseService::class)->releaseBatch();

    expect($task->refresh()->status)->toBe(TaskStatus::Published)
        ->and($task->completed_at)->not->toBeNull();
});

it('releases through the artisan command', function (): void {
    Product::factory()->approved()->count(3)->create();

    $this->artisan('products:release', ['--limit' => 2])
        ->expectsOutputToContain('Released 2 product(s).')
        ->assertSuccessful();

    expect(Product::where('status', ProductStatus::Published)->count())->toBe(2);
});
