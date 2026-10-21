<?php

use App\Enums\AccessType;
use App\Models\Component;
use App\Models\Product;
use App\Models\SubscriptionTier;
use App\Models\User;

/**
 * Abstract Papers: the one component that writes nothing. It carries every
 * published abstract in the catalogue, and each entry names the product it was
 * lifted from so the frontend can open the full document.
 */
function abstractPapers(): Component
{
    return Component::factory()->virtual()->create([
        'code' => 'AP',
        'name' => 'Abstract Papers',
        'sort_order' => 99,
    ]);
}

it('lists the abstracts of every published product, whichever component wrote it', function (): void {
    abstractPapers();

    $daily = Component::factory()->create(['code' => 'DB', 'name' => 'Daily Brief']);
    $weekly = Component::factory()->create(['code' => 'WH', 'name' => 'Weekly Highlights']);

    $first = Product::factory()->for($daily, 'component')->published()->create([
        'title' => 'Sovereign clearing risk',
        'abstract' => 'A full abstract that is considerably longer than the hundred and sixty characters '
            .'a catalogue excerpt would be cut to, because in this listing the abstract is the content '
            .'itself rather than a teaser for something else.',
        'published_at' => now()->subDay(),
    ]);

    $second = Product::factory()->for($weekly, 'component')->published()->create([
        'published_at' => now(),
    ]);

    $response = $this->getJson(route('api.catalog.abstracts.index'))->assertOk();

    expect($response->json('data'))->toHaveCount(2);

    // Newest first, each naming the product it belongs to and the line it came
    // from — and carrying the abstract in full, not an excerpt.
    $response
        ->assertJsonPath('data.0.product', $second->public_id)
        ->assertJsonPath('data.1.product', $first->public_id)
        ->assertJsonPath('data.1.title', 'Sovereign clearing risk')
        ->assertJsonPath('data.1.abstract', $first->abstract)
        ->assertJsonPath('data.1.component.code', 'DB');
});

it('never carries a body, redacted or otherwise', function (): void {
    abstractPapers();

    Product::factory()->published()->redacted()->create();

    $entry = $this->getJson(route('api.catalog.abstracts.index'))->assertOk()->json('data.0');

    // Opening the full document goes through GET /products/{product}, which
    // applies the caller's tier. This listing is public, so it must not.
    expect($entry)->not->toHaveKeys(['body', 'redactedBody', 'redacted_body']);
});

it('leaves out drafts, hidden products and anything without an abstract', function (): void {
    abstractPapers();

    $visible = Product::factory()->published()->create();
    Product::factory()->create();                                    // draft
    Product::factory()->published()->hidden()->create();             // vault-hidden
    Product::factory()->published()->withoutAbstract()->create();    // nothing to show

    $response = $this->getJson(route('api.catalog.abstracts.index'))->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.product'))->toBe($visible->public_id);
});

it('narrows to one product line, and searches titles and abstracts alike', function (): void {
    abstractPapers();

    $daily = Component::factory()->create(['code' => 'DB']);
    $weekly = Component::factory()->create(['code' => 'WH']);

    Product::factory()->for($daily, 'component')->published()->create([
        'title' => 'Clearing systems', 'abstract' => 'On balkanisation.',
    ]);
    Product::factory()->for($weekly, 'component')->published()->create([
        'title' => 'Something else', 'abstract' => 'On balkanisation as well.',
    ]);

    expect($this->getJson(route('api.catalog.abstracts.index', ['component' => 'DB']))->assertOk()->json('data'))
        ->toHaveCount(1);

    // The search reaches the abstract, not only the title: in a list of
    // abstracts, matching titles alone would ignore the text on the page.
    expect($this->getJson(route('api.catalog.abstracts.index', ['search' => 'balkanisation']))->assertOk()->json('data'))
        ->toHaveCount(2);
});

it('serves the same listing when the component tile is drilled into', function (): void {
    $abstracts = abstractPapers();
    $product = Product::factory()->published()->create();

    // The grid drills into every tile the same way, so the frontend needs no
    // special case for the one that is an index.
    $this->getJson(route('api.catalog.components.products.index', $abstracts))
        ->assertOk()
        ->assertJsonPath('data.0.product', $product->public_id);
});

it('counts the whole catalogue on its catalogue tile', function (): void {
    abstractPapers();

    $daily = Component::factory()->create(['code' => 'DB', 'sort_order' => 1]);
    Product::factory()->count(3)->for($daily, 'component')->published()->create();
    Product::factory()->for($daily, 'component')->published()->hidden()->create();

    $tiles = collect($this->getJson(route('api.catalog.components.index'))->assertOk()->json('data'))
        ->keyBy('code');

    // A tile reading "4 abstracts" over a list of 3 is worse than either
    // number alone, so the count comes from the same query as the listing.
    expect($tiles['AP']['productsCount'])->toBe(3)
        ->and($tiles['AP']['isVirtual'])->toBeTrue()
        ->and($tiles['DB']['productsCount'])->toBe(3)
        ->and($tiles['DB']['isVirtual'])->toBeFalse();
});

// ------------------------------------------------- it generates nothing

it('refuses a topic against it', function (): void {
    $abstracts = abstractPapers();

    $admin = User::factory()->create();
    $admin->assignRole(User::ADMIN);

    $this->actingAs($admin)
        ->postJson(route('api.admin.topics.store'), [
            'title' => 'A topic that cannot be written',
            'component' => $abstracts->public_id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('component');
});

it('refuses a tier allocation against it', function (): void {
    $abstracts = abstractPapers();
    $tier = SubscriptionTier::factory()->create();

    $admin = User::factory()->create();
    $admin->assignRole(User::SYSTEM_ADMIN);

    // An abstract is public to every visitor (FR-09), so there is nothing for
    // a tier to unlock — an allocation here would promise what is never checked.
    $this->actingAs($admin)
        ->putJson(route('api.admin.tiers.allocations.update', $tier), [
            'allocations' => [
                ['component' => 'AP', 'access_type' => AccessType::Unlimited->value],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('allocations.0.component');
});

it('stays out of the vault, having nothing to proofread', function (): void {
    abstractPapers();
    Component::factory()->create(['code' => 'DB']);

    $admin = User::factory()->create();
    $admin->assignRole(User::SYSTEM_ADMIN);

    $codes = collect($this->actingAs($admin)
        ->getJson(route('api.admin.vault.components.index'))
        ->assertOk()
        ->json('data'))->pluck('code');

    expect($codes)->toContain('DB')->not->toContain('AP');
});
