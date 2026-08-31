<?php

use App\Models\Product;
use App\Models\ProductRating;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Models\TierAllocation;
use App\Models\User;

function entitledSubscriber(Product $product, bool $entitled = true): User
{
    $user = User::factory()->create();
    $user->assignRole('subscriber');

    $tier = SubscriptionTier::factory()->create();
    $allocation = TierAllocation::factory();

    ($entitled ? $allocation : $allocation->denied())
        ->create(['tier_id' => $tier->id, 'component_id' => $product->component_id]);

    Subscription::factory()->for($user)->create(['tier_id' => $tier->id]);

    return $user;
}

it('records a rating for an entitled subscriber', function (): void {
    $product = Product::factory()->published()->create();
    $subscriber = entitledSubscriber($product);

    // First rating creates, so 201; re-rating updates in place, so 200.
    $this->actingAs($subscriber)
        ->putJson(route('api.products.rating.update', $product), ['stars' => 4])
        ->assertCreated()
        ->assertJsonPath('data.stars', 4);

    $this->actingAs($subscriber)
        ->putJson(route('api.products.rating.update', $product), ['stars' => 5])
        ->assertOk()
        ->assertJsonPath('data.stars', 5);

    expect(ProductRating::where('product_id', $product->id)->value('stars'))->toBe(5);
});

it('replaces rather than stacks when a subscriber re-rates', function (): void {
    $product = Product::factory()->published()->create();
    $subscriber = entitledSubscriber($product);

    $this->actingAs($subscriber)->putJson(route('api.products.rating.update', $product), ['stars' => 2]);
    $this->actingAs($subscriber)->putJson(route('api.products.rating.update', $product), ['stars' => 5]);

    expect(ProductRating::where('product_id', $product->id)->count())->toBe(1)
        ->and(ProductRating::where('product_id', $product->id)->value('stars'))->toBe(5);
});

it('refuses a rating from a subscriber who cannot read the product', function (): void {
    // Otherwise every Freemium account could star every product in the
    // catalogue from the locked preview alone.
    $product = Product::factory()->published()->create();

    $this->actingAs(entitledSubscriber($product, entitled: false))
        ->putJson(route('api.products.rating.update', $product), ['stars' => 5])
        ->assertForbidden();

    expect(ProductRating::count())->toBe(0);
});

it('rejects a rating outside one to five', function (): void {
    $product = Product::factory()->published()->create();

    $this->actingAs(entitledSubscriber($product))
        ->putJson(route('api.products.rating.update', $product), ['stars' => 6])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('stars');
});

it('returns null rather than 404 before a subscriber has rated', function (): void {
    $product = Product::factory()->published()->create();

    $this->actingAs(entitledSubscriber($product))
        ->getJson(route('api.products.rating.show', $product))
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('lets a subscriber withdraw a rating', function (): void {
    $product = Product::factory()->published()->create();
    $subscriber = entitledSubscriber($product);

    $this->actingAs($subscriber)->putJson(route('api.products.rating.update', $product), ['stars' => 3]);

    $this->actingAs($subscriber)
        ->deleteJson(route('api.products.rating.destroy', $product))
        ->assertNoContent();

    expect(ProductRating::count())->toBe(0);
});

it('surfaces the average on the public preview', function (): void {
    $product = Product::factory()->published()->create();

    ProductRating::factory()->count(2)->create(['product_id' => $product->id]);
    ProductRating::where('product_id', $product->id)->get()
        ->each(fn ($r, $i) => $r->update(['stars' => $i === 0 ? 3 : 4]));

    $this->getJson(route('api.products.preview', $product))
        ->assertOk()
        ->assertJsonPath('data.averageRating', 3.5)
        ->assertJsonPath('data.ratingsCount', 2);
});

it('does not let an anonymous visitor rate', function (): void {
    $product = Product::factory()->published()->create();

    $this->putJson(route('api.products.rating.update', $product), ['stars' => 5])
        ->assertUnauthorized();
});
