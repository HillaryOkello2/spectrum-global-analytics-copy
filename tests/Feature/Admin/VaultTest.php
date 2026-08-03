<?php

use App\Enums\ProductStatus;
use App\Models\Component;
use App\Models\Product;
use App\Models\User;

function vaultAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

it('lists every product of a component with its status, including hidden and unpublished', function (): void {
    $component = Component::factory()->create();
    $published = Product::factory()->published()->for($component)->create();
    $hidden = Product::factory()->published()->hidden()->for($component)->create();
    $draft = Product::factory()->for($component)->create();

    $response = $this->actingAs(vaultAdmin())
        ->getJson(route('api.admin.vault.components.products.index', $component))
        ->assertOk()
        ->assertJsonCount(3, 'data');

    $statuses = $response->json('meta.statuses');

    expect($statuses[$published->public_id])->toBe(['status' => ProductStatus::Published->value, 'isHidden' => false])
        ->and($statuses[$hidden->public_id]['isHidden'])->toBeTrue()
        ->and($statuses[$draft->public_id]['status'])->toBe(ProductStatus::Draft->value);
});

it('returns an empty vault listing for a component with no products', function (): void {
    $this->actingAs(vaultAdmin())
        ->getJson(route('api.admin.vault.components.products.index', Component::factory()->create()))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('counts every product on vault components, drafts and hidden included', function (): void {
    $component = Component::factory()->create();
    Product::factory()->published()->count(2)->for($component)->create();
    Product::factory()->for($component)->create(); // draft
    Product::factory()->published()->hidden()->for($component)->create(); // vaulted

    // The public catalogue would report 2 here; the vault lists all four.
    $this->actingAs(vaultAdmin())
        ->getJson(route('api.admin.vault.components.index'))
        ->assertOk()
        ->assertJsonPath('data.0.productsCount', 4);
});

it('forbids subscribers from the vault', function (): void {
    $subscriber = User::factory()->create();
    $subscriber->assignRole('subscriber');

    $this->actingAs($subscriber)
        ->getJson(route('api.admin.vault.components.products.index', Component::factory()->create()))
        ->assertForbidden();
});
