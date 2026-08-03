<?php

use App\Models\Component;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Models\TierAllocation;
use App\Models\User;

function subscriberFor(SubscriptionTier $tier): User
{
    $user = User::factory()->create();
    $user->assignRole('subscriber');

    Subscription::factory()->for($user)->create(['tier_id' => $tier->id]);

    return $user;
}

it('returns the full body for an unlimited allocation', function (): void {
    $product = Product::factory()->published()->create();
    $tier = SubscriptionTier::factory()->create();
    TierAllocation::factory()->create(['tier_id' => $tier->id, 'component_id' => $product->component_id]);

    $this->actingAs(subscriberFor($tier))
        ->getJson(route('api.products.show', $product))
        ->assertOk()
        ->assertJsonPath('data.locked', false)
        ->assertJsonPath('data.body', $product->body);
});

it('returns only the preview for a denied allocation', function (): void {
    $product = Product::factory()->published()->create();
    $tier = SubscriptionTier::factory()->create();
    TierAllocation::factory()->denied()->create(['tier_id' => $tier->id, 'component_id' => $product->component_id]);

    $this->actingAs(subscriberFor($tier))
        ->getJson(route('api.products.show', $product))
        ->assertOk()
        ->assertJsonPath('data.locked', true)
        ->assertJsonPath('meta.reason', 'denied')
        ->assertJsonMissingPath('data.body');
});

it('meters quota, allows free re-reads, and blocks at the limit', function (): void {
    $component = Component::factory()->create();
    $products = Product::factory()->published()->count(3)->for($component)->create();
    $tier = SubscriptionTier::factory()->create();
    TierAllocation::factory()->metered(2)->create(['tier_id' => $tier->id, 'component_id' => $component->id]);

    $user = subscriberFor($tier);

    // First two distinct products consume the quota.
    $this->actingAs($user)->getJson(route('api.products.show', $products[0]))
        ->assertOk()->assertJsonPath('meta.used', 1)->assertJsonPath('meta.limit', 2);
    $this->actingAs($user)->getJson(route('api.products.show', $products[1]))
        ->assertOk()->assertJsonPath('meta.used', 2);

    // Re-reading an unlocked product does not double-charge.
    $this->actingAs($user)->getJson(route('api.products.show', $products[0]))
        ->assertOk()->assertJsonPath('meta.used', 2);

    // A third distinct product exceeds the monthly limit.
    $this->actingAs($user)->getJson(route('api.products.show', $products[2]))
        ->assertForbidden()
        ->assertJsonPath('code', 'quota_exhausted')
        ->assertJsonPath('meta.used', 2);
});

it('meters each component separately', function (): void {
    // Components are global, so "A1 max N/month" is one quota — and exhausting
    // it must not spill over onto A2's separate allowance.
    $first = Component::factory()->create(['code' => 'A1']);
    $second = Component::factory()->create(['code' => 'A2']);

    $firstProduct = Product::factory()->published()->for($first)->create();
    $secondProduct = Product::factory()->published()->for($second)->create();

    $tier = SubscriptionTier::factory()->create();
    TierAllocation::factory()->metered(1)->create(['tier_id' => $tier->id, 'component_id' => $first->id]);
    TierAllocation::factory()->metered(1)->create(['tier_id' => $tier->id, 'component_id' => $second->id]);

    $user = subscriberFor($tier);

    // Spend A1's single unit...
    $this->actingAs($user)->getJson(route('api.products.show', $firstProduct))
        ->assertOk()->assertJsonPath('meta.used', 1);

    // ...and A2's allowance is untouched.
    $this->actingAs($user)->getJson(route('api.products.show', $secondProduct))
        ->assertOk()->assertJsonPath('meta.used', 1);
});

it('shows visitors only the abstract via the preview endpoint', function (): void {
    $product = Product::factory()->published()->create();

    $response = $this->getJson(route('api.products.preview', $product))
        ->assertOk()
        ->assertJsonPath('data.locked', true)
        ->assertJsonMissingPath('data.body')
        ->assertJsonMissingPath('data.redactedBody');

    expect($response->json('data.abstract'))->toBe($product->abstract);
});

it('hides vaulted products from subscribers entirely', function (): void {
    $product = Product::factory()->published()->hidden()->create();
    $tier = SubscriptionTier::factory()->create();
    TierAllocation::factory()->create(['tier_id' => $tier->id, 'component_id' => $product->component_id]);

    $this->actingAs(subscriberFor($tier))
        ->getJson(route('api.products.show', $product))
        ->assertNotFound();

    $this->getJson(route('api.products.preview', $product))->assertNotFound();
});

it('keeps transactional (A14) products preview-only without a purchase', function (): void {
    $component = Component::factory()->transactional()->create();
    $product = Product::factory()->published()->for($component)->create();
    $tier = SubscriptionTier::factory()->create();
    TierAllocation::factory()->create(['tier_id' => $tier->id, 'component_id' => $component->id]);

    $this->actingAs(subscriberFor($tier))
        ->getJson(route('api.products.show', $product))
        ->assertOk()
        ->assertJsonPath('data.locked', true)
        ->assertJsonMissingPath('data.body');
});

it('serves the redacted document where the tier withholds the full one', function (): void {
    $product = Product::factory()->published()->redacted()->create();
    $tier = SubscriptionTier::factory()->create();
    TierAllocation::factory()->denied()->create(['tier_id' => $tier->id, 'component_id' => $product->component_id]);

    $this->actingAs(subscriberFor($tier))
        ->getJson(route('api.products.show', $product))
        ->assertOk()
        ->assertJsonPath('data.redacted', true)
        ->assertJsonPath('data.locked', true)
        ->assertJsonPath('data.redactedBody', $product->redacted_body)
        // The unredacted document must not leak through this path (R-04).
        ->assertJsonMissingPath('data.body');
});

it('falls back to the abstract when no redaction has been approved', function (): void {
    // Redacted text exists but was never approved — it must not be served.
    $product = Product::factory()->published()->create([
        'redacted_body' => 'Draft redaction, not signed off.',
        'redaction_approved' => false,
    ]);
    $tier = SubscriptionTier::factory()->create();
    TierAllocation::factory()->denied()->create(['tier_id' => $tier->id, 'component_id' => $product->component_id]);

    $this->actingAs(subscriberFor($tier))
        ->getJson(route('api.products.show', $product))
        ->assertOk()
        ->assertJsonPath('data.abstract', $product->abstract)
        ->assertJsonMissingPath('data.redactedBody')
        ->assertJsonMissingPath('data.body');
});

it('serves the redaction instead of a 403 once metered quota runs out', function (): void {
    $component = Component::factory()->create();
    $spent = Product::factory()->published()->for($component)->create();
    $next = Product::factory()->published()->redacted()->for($component)->create();

    $tier = SubscriptionTier::factory()->create();
    TierAllocation::factory()->metered(1)->create(['tier_id' => $tier->id, 'component_id' => $component->id]);

    $user = subscriberFor($tier);

    $this->actingAs($user)->getJson(route('api.products.show', $spent))->assertOk();

    $this->actingAs($user)->getJson(route('api.products.show', $next))
        ->assertOk()
        ->assertJsonPath('data.redacted', true)
        ->assertJsonMissingPath('data.body');
});

it('still 403s on exhausted quota when there is no redaction to fall back to', function (): void {
    $component = Component::factory()->create();
    $spent = Product::factory()->published()->for($component)->create();
    $next = Product::factory()->published()->for($component)->create();

    $tier = SubscriptionTier::factory()->create();
    TierAllocation::factory()->metered(1)->create(['tier_id' => $tier->id, 'component_id' => $component->id]);

    $user = subscriberFor($tier);

    $this->actingAs($user)->getJson(route('api.products.show', $spent))->assertOk();

    $this->actingAs($user)->getJson(route('api.products.show', $next))
        ->assertForbidden()
        ->assertJsonPath('code', 'quota_exhausted');
});

it('never gives a visitor the redacted document', function (): void {
    $product = Product::factory()->published()->redacted()->create();

    $this->getJson(route('api.products.preview', $product))
        ->assertOk()
        ->assertJsonPath('data.abstract', $product->abstract)
        ->assertJsonMissingPath('data.redactedBody')
        ->assertJsonMissingPath('data.body');
});
