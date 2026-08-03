<?php

use App\Models\Component;
use App\Models\Product;

it('lists components for the public catalogue', function (): void {
    Component::factory()->count(3)->create();

    $this->getJson(route('api.catalog.components.index'))
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('resolves a component by public id or by its unique code', function (): void {
    $component = Component::factory()->create(['code' => 'A4']);
    Product::factory()->published()->count(2)->for($component)->create();

    $this->getJson(route('api.catalog.components.products.index', $component->public_id))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson(route('api.catalog.components.products.index', 'A4'))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson(route('api.catalog.components.products.index', 'A99'))
        ->assertNotFound();
});

it('lists only visible products under a component', function (): void {
    $component = Component::factory()->create();
    Product::factory()->published()->count(2)->for($component)->create();
    Product::factory()->for($component)->create(); // draft
    Product::factory()->published()->hidden()->for($component)->create(); // vaulted

    $this->getJson(route('api.catalog.components.products.index', $component))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

/**
 * Fixture shared by the counter tests: two components, four products under the
 * first (2 visible, 1 draft, 1 hidden) and 1 visible under the second.
 * Visible totals = 2 and 1; all-products totals = 4 and 1.
 */
function componentsWithCountableProducts(): void
{
    $first = Component::factory()->create(['sort_order' => 1]);
    Product::factory()->published()->count(2)->for($first)->create();
    Product::factory()->for($first)->create(); // draft
    Product::factory()->published()->hidden()->for($first)->create(); // vaulted

    $second = Component::factory()->create(['sort_order' => 2]);
    Product::factory()->published()->for($second)->create();
}

it('counts only visible products on the public component listing', function (): void {
    componentsWithCountableProducts();

    $this->getJson(route('api.catalog.components.index'))
        ->assertOk()
        ->assertJsonPath('data.0.productsCount', 2)
        ->assertJsonPath('data.0.sortOrder', 1)
        ->assertJsonPath('data.1.productsCount', 1)
        ->assertJsonPath('data.1.sortOrder', 2);
});

it('omits the product count from components embedded in product payloads', function (): void {
    $component = Component::factory()->create();
    $product = Product::factory()->published()->for($component)->create();

    // A count here would be meaningless (and an N+1 waiting to happen), so the
    // key must be absent rather than zero.
    $this->getJson(route('api.catalog.components.products.index', $component))
        ->assertOk()
        ->assertJsonMissingPath('data.0.component.productsCount');

    $this->getJson(route('api.products.preview', $product))
        ->assertOk()
        ->assertJsonMissingPath('data.component.productsCount');
});

it('exposes the product code and abstract but never the body on a preview', function (): void {
    $product = Product::factory()->published()->create([
        'code' => 'SGA.A4.2026-08.017',
        'abstract' => 'The abstract a visitor is allowed to read.',
        'body' => 'CONFIDENTIAL FULL DOCUMENT',
    ]);

    $this->getJson(route('api.products.preview', $product))
        ->assertOk()
        ->assertJsonPath('data.code', 'SGA.A4.2026-08.017')
        ->assertJsonPath('data.abstract', 'The abstract a visitor is allowed to read.')
        ->assertJsonPath('data.locked', true)
        ->assertJsonMissingPath('data.body')
        ->assertJsonMissingPath('data.redactedBody');
});

it('excerpts the abstract, not the body, on catalogue listings', function (): void {
    $component = Component::factory()->create();
    Product::factory()->published()->for($component)->create([
        'abstract' => 'Abstract text.',
        'body' => 'CONFIDENTIAL FULL DOCUMENT',
    ]);

    $this->getJson(route('api.catalog.components.products.index', $component))
        ->assertOk()
        ->assertJsonPath('data.0.excerpt', 'Abstract text.')
        ->assertJsonMissingPath('data.0.body');
});
