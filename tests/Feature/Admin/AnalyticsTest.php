<?php

use App\Models\Component;
use App\Models\Product;
use App\Models\User;

function analyticsAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(User::ADMIN);

    return $admin;
}

it('reports published products per component, counting vault-hidden ones too', function (): void {
    $component = Component::factory()->create(['name' => 'Daily Brief', 'code' => 'A4', 'sort_order' => 1]);

    Product::factory()->published()->count(2)->for($component)->create();
    Product::factory()->published()->hidden()->for($component)->create();
    Product::factory()->for($component)->create(); // draft — excluded

    // Deliberately 3, not the catalogue's visible-only 2: hiding is a
    // subscriber-facing control, not a measure of editorial output.
    $this->actingAs(analyticsAdmin())
        ->getJson(route('api.admin.analytics.products-by-component'))
        ->assertOk()
        ->assertJsonPath('data.0.component', 'Daily Brief')
        ->assertJsonPath('data.0.code', 'A4')
        ->assertJsonPath('data.0.productsPublished', 3);
});

it('reports a zero count for a component with no products', function (): void {
    Component::factory()->create(['sort_order' => 1]);

    $this->actingAs(analyticsAdmin())
        ->getJson(route('api.admin.analytics.products-by-component'))
        ->assertOk()
        ->assertJsonPath('data.0.productsPublished', 0);
});

it('forbids subscribers from the analytics endpoints', function (): void {
    $subscriber = User::factory()->create();
    $subscriber->assignRole(User::SUBSCRIBER);

    $this->actingAs($subscriber)
        ->getJson(route('api.admin.analytics.products-by-component'))
        ->assertForbidden();
});
