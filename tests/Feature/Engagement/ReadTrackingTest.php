<?php

use App\Models\Product;
use App\Models\ProductRead;

it('records a read when the full document is served', function (): void {
    $product = Product::factory()->published()->create();

    $this->actingAs(entitledSubscriber($product))
        ->getJson(route('api.products.show', $product))
        ->assertOk();

    expect(ProductRead::where('product_id', $product->id)->count())->toBe(1)
        ->and($product->refresh()->reads_count)->toBe(1);
});

it('counts every read, not just the first', function (): void {
    // This is what separates product_reads from product_consumptions, which
    // deduplicates and so cannot answer "most read".
    $product = Product::factory()->published()->create();
    $subscriber = entitledSubscriber($product);

    $this->actingAs($subscriber)->getJson(route('api.products.show', $product));
    $this->actingAs($subscriber)->getJson(route('api.products.show', $product));
    $this->actingAs($subscriber)->getJson(route('api.products.show', $product));

    expect($product->refresh()->reads_count)->toBe(3);
});

it('records a read when the redacted document is served', function (): void {
    $product = Product::factory()->published()->redacted()->create();

    $this->actingAs(entitledSubscriber($product, entitled: false))
        ->getJson(route('api.products.show', $product))
        ->assertOk()
        ->assertJsonPath('data.redacted', true);

    expect($product->refresh()->reads_count)->toBe(1);
});

it('does not count a locked preview as a read', function (): void {
    // The subscriber saw an abstract and a paywall — nothing was read.
    $product = Product::factory()->published()->create();

    $this->actingAs(entitledSubscriber($product, entitled: false))
        ->getJson(route('api.products.show', $product))
        ->assertOk()
        ->assertJsonPath('data.locked', true);

    expect($product->refresh()->reads_count)->toBe(0);
});

it('does not count the public preview as a read', function (): void {
    $product = Product::factory()->published()->create();

    $this->getJson(route('api.products.preview', $product))->assertOk();

    expect($product->refresh()->reads_count)->toBe(0);
});
