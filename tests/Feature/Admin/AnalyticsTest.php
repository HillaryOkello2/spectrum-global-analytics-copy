<?php

use App\Enums\ProductStatus;
use App\Enums\TaskStatus;
use App\Models\Component;
use App\Models\GenerationTask;
use App\Models\Product;
use App\Models\ProductRating;
use App\Models\ProductRead;
use App\Models\Topic;
use App\Models\User;
use Spatie\Permission\Models\Role;

function analyticsAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(User::ADMIN);

    return $admin;
}

it('reports published products per component, counting vault-hidden ones too', function (): void {
    $component = Component::factory()->create(['name' => 'Daily Brief', 'code' => 'DB', 'sort_order' => 1]);

    Product::factory()->published()->count(2)->for($component)->create();
    Product::factory()->published()->hidden()->for($component)->create();
    Product::factory()->for($component)->create(); // draft — excluded

    // Deliberately 3, not the catalogue's visible-only 2: hiding is a
    // subscriber-facing control, not a measure of editorial output.
    $this->actingAs(analyticsAdmin())
        ->getJson(route('api.admin.analytics.products-by-component'))
        ->assertOk()
        ->assertJsonPath('data.0.component', 'Daily Brief')
        ->assertJsonPath('data.0.code', 'DB')
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

it('groups subscribers by country', function (): void {
    User::factory()->count(2)->create(['country' => 'Kenya'])->each->assignRole(User::SUBSCRIBER);
    User::factory()->create(['country' => 'Nigeria'])->assignRole(User::SUBSCRIBER);
    // Staff are not subscribers and must not appear in the location chart.
    User::factory()->create(['country' => 'Kenya'])->assignRole(User::ADMIN);

    $this->actingAs(analyticsAdmin())
        ->getJson(route('api.admin.analytics.subscribers-by-location'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.country', 'Kenya')
        ->assertJsonPath('data.0.subscribers', 2)
        ->assertJsonPath('data.1.country', 'Nigeria');
});

it('ranks the most read products', function (): void {
    $quiet = Product::factory()->published()->create(['reads_count' => 3]);
    $popular = Product::factory()->published()->create(['reads_count' => 40]);
    Product::factory()->published()->create(['reads_count' => 0]); // never read — excluded

    $this->actingAs(analyticsAdmin())
        ->getJson(route('api.admin.analytics.most-read-products'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.publicId', $popular->public_id)
        ->assertJsonPath('data.0.reads', 40)
        ->assertJsonPath('data.1.publicId', $quiet->public_id);
});

it('ranks most read within a date window from the ledger', function (): void {
    $recent = Product::factory()->published()->create(['reads_count' => 1]);
    $historic = Product::factory()->published()->create(['reads_count' => 500]);

    ProductRead::factory()->count(2)->create(['product_id' => $recent->id, 'read_at' => now()->subDay()]);
    // All-time leader, but every read is outside the window.
    ProductRead::factory()->count(9)->create(['product_id' => $historic->id, 'read_at' => now()->subYear()]);

    $this->actingAs(analyticsAdmin())
        ->getJson(route('api.admin.analytics.most-read-products', ['from' => now()->subWeek()->toDateString()]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.publicId', $recent->public_id)
        ->assertJsonPath('data.0.reads', 2);
});

it('reports average star ratings, best first', function (): void {
    $good = Product::factory()->published()->create();
    $poor = Product::factory()->published()->create();
    Product::factory()->published()->create(); // unrated — excluded

    ProductRating::factory()->create(['product_id' => $good->id, 'stars' => 5]);
    ProductRating::factory()->create(['product_id' => $good->id, 'stars' => 4]);
    ProductRating::factory()->create(['product_id' => $poor->id, 'stars' => 1]);

    $this->actingAs(analyticsAdmin())
        ->getJson(route('api.admin.analytics.product-ratings'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.publicId', $good->public_id)
        ->assertJsonPath('data.0.averageRating', 4.5)
        ->assertJsonPath('data.0.ratingsCount', 2)
        // JSON-encodes a whole average as 1, not 1.0 — both are numbers.
        ->assertJsonPath('data.1.averageRating', 1);
});

it('reports rejections per component with their notes', function (): void {
    $component = Component::factory()->create(['code' => 'RP', 'sort_order' => 1]);
    $product = Product::factory()->for($component)->create(['status' => ProductStatus::Rejected]);

    // Bind the task to a topic on the same component, so the factory does not
    // spawn a second component that then sorts ahead of this one.
    GenerationTask::factory()->for(Topic::factory()->for($component))->create([
        'product_id' => $product->id,
        'status' => TaskStatus::Rejected,
        'rejection_note' => 'Descends into tactical reporting in section 3.',
    ]);

    $this->actingAs(analyticsAdmin())
        ->getJson(route('api.admin.analytics.rejections'))
        ->assertOk()
        ->assertJsonPath('data.byComponent.0.code', 'RP')
        ->assertJsonPath('data.byComponent.0.rejected', 1)
        ->assertJsonPath('data.recent.0.productCode', $product->code)
        ->assertJsonPath('data.recent.0.note', 'Descends into tactical reporting in section 3.');
});

it('denies analytics to a staff account without the permission', function (): void {
    $staff = User::factory()->create();
    $staff->assignRole(
        Role::findOrCreate('editor', 'web')->syncPermissions(['access admin portal']),
    );

    $this->actingAs($staff)
        ->getJson(route('api.admin.analytics.subscribers-by-location'))
        ->assertForbidden();
});
