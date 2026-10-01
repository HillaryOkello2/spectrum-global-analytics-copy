<?php

use App\Models\Component;
use App\Models\Product;
use App\Models\ProductRating;
use App\Models\ProductRead;
use App\Models\User;

function vaultStaff(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(User::ADMIN);

    return $admin;
}

it('carries ratings, workflow state and read count on every vault row', function (): void {
    $component = Component::factory()->create();
    $product = Product::factory()->for($component)->published()->hidden()->create(['reads_count' => 7]);

    ProductRating::factory()->for($product)->for(User::factory())->create(['stars' => 4]);

    $this->actingAs(vaultStaff())
        ->getJson(route('api.admin.vault.components.products.index', $component))
        ->assertOk()
        // A whole-star average encodes as 4, not 4.0 — compare numerically.
        ->assertJsonPath('data.0.averageRating', fn ($value) => (float) $value === 4.0)
        ->assertJsonPath('data.0.ratingsCount', 1)
        ->assertJsonPath('data.0.status', 'published')
        ->assertJsonPath('data.0.isHidden', true)
        ->assertJsonPath('data.0.readsCount', 7)
        // Still published under meta for clients on the older shape.
        ->assertJsonPath("meta.statuses.{$product->public_id}.status", 'published');
});

it('serves staff one product in full, whatever its status', function (): void {
    $product = Product::factory()->approved()->create(['body' => 'Full body text.']);

    $this->actingAs(vaultStaff())
        ->getJson(route('api.admin.vault.products.show', $product))
        ->assertOk()
        ->assertJsonPath('data.body', 'Full body text.')
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.readsCount', 0);
});

it('refuses a staff token on the subscriber reader, which is why the vault route exists', function (): void {
    // The frontend had been falling back to the task board to read a body for
    // exactly this reason.
    $product = Product::factory()->published()->create();

    $this->actingAs(vaultStaff())
        ->getJson(route('api.products.show', $product))
        ->assertForbidden();
});

it('does not count editorial review as a read', function (): void {
    $product = Product::factory()->published()->create();

    $this->actingAs(vaultStaff())
        ->getJson(route('api.admin.vault.products.show', $product))
        ->assertOk();

    expect($product->refresh()->reads_count)->toBe(0)
        ->and(ProductRead::count())->toBe(0);
});
