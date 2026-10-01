<?php

use App\Models\Component;
use App\Models\Product;
use App\Models\ProductRating;
use App\Models\User;

function starsOn(Product $product, array $stars): void
{
    foreach ($stars as $value) {
        ProductRating::factory()->for($product)->for(User::factory())->create(['stars' => $value]);
    }
}

function catalogueAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(User::ADMIN);

    return $admin;
}

it('rates a component by averaging the ratings of its visible products', function (): void {
    // Averaging client-side would mean a request per product on every page
    // load of the grid, which is why this is aggregated here.
    $component = Component::factory()->create();
    $visible = Product::factory()->for($component)->published()->create();
    $hidden = Product::factory()->for($component)->published()->hidden()->create();

    starsOn($visible, [5, 4]);
    starsOn($hidden, [1]);

    $this->getJson(route('api.catalog.components.index'))
        ->assertOk()
        ->assertJsonPath('data.0.averageRating', 4.5)
        ->assertJsonPath('data.0.ratingsCount', 2);
});

it('counts every product in the vault, hidden and unpublished included', function (): void {
    $component = Component::factory()->create();
    $published = Product::factory()->for($component)->published()->create();
    $draft = Product::factory()->for($component)->create();

    starsOn($published, [5, 4]);
    starsOn($draft, [1]);

    $this->actingAs(catalogueAdmin())
        ->getJson(route('api.admin.vault.components.index'))
        ->assertOk()
        ->assertJsonPath('data.0.ratingsCount', 3)
        ->assertJsonPath('data.0.averageRating', 3.33);
});

it('omits the average for a component nobody has rated, rather than sending zero', function (): void {
    Component::factory()->create();

    // A zero average would render as half a star on the grid; absent says
    // "no ratings yet", which is what a count of 0 confirms.
    $this->getJson(route('api.catalog.components.index'))
        ->assertOk()
        ->assertJsonMissingPath('data.0.averageRating')
        ->assertJsonPath('data.0.ratingsCount', 0);
});
