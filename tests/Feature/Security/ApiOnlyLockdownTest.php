<?php

use App\Models\Component;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Models\TierAllocation;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config(['security.api_only' => true]);
});

it('hides the non-API surface behind a 404', function (string $path): void {
    $this->get($path)->assertNotFound();
})->with([
    'root banner' => '/',
    'scribe docs' => '/docs',
    'postman collection' => '/docs.postman',
    'openapi spec' => '/docs.openapi',
    'horizon dashboard' => '/horizon',
    'horizon sub-view' => '/horizon/failed',
    'horizon internal api' => '/horizon/api/stats',
    'storage files' => '/storage/anything.txt',
    'sanctum csrf cookie' => '/sanctum/csrf-cookie',
]);

it('returns an empty body so the response reveals nothing', function (): void {
    $response = $this->get('/horizon');

    expect($response->getContent())->toBe('');
});

it('still serves the API', function (): void {
    Component::factory()->create();

    $this->getJson('/api/v1/catalog/components')->assertOk();
});

it('still serves the health check', function (): void {
    $this->get('/up')->assertOk();
});

it('leaves API 404s and auth errors intact', function (): void {
    // The lockdown must not mask the API's own responses — a real 401 has to
    // stay a 401, or the frontend cannot tell "not logged in" from "blocked".
    $this->getJson('/api/v1/admin/users')->assertUnauthorized();
});

it('exposes everything again when the lockdown is off', function (): void {
    config(['security.api_only' => false]);

    $this->get('/')->assertOk();
});

it('honours a configured status code', function (): void {
    config(['security.api_only_status' => 503]);

    $this->get('/horizon')->assertStatus(503);
});

it('honours a custom allowed-path list', function (): void {
    config(['security.allowed_paths' => ['api/*']]);

    $this->get('/up')->assertNotFound();
});

it('denies everything but the API when the allow list is empty', function (): void {
    config(['security.allowed_paths' => []]);

    $this->get('/up')->assertNotFound();
    $this->get('/horizon')->assertNotFound();
});

it('strips headers that identify the stack', function (): void {
    $response = $this->get('/horizon');

    expect($response->headers->has('X-Powered-By'))->toBeFalse()
        ->and($response->headers->has('Server'))->toBeFalse();
});

it('masks a thrown exception on a non-API path', function (): void {
    // Something earlier in the global stack can throw before RestrictToApi runs;
    // the caller must still get a bare status, never a framework error page.
    Route::get('/boom', fn () => throw new RuntimeException('leaky internals'));

    $response = $this->get('/boom');

    $response->assertNotFound();
    expect($response->getContent())->toBe('')
        ->and($response->getContent())->not->toContain('leaky internals');
});

it('leaves thrown exceptions on API paths alone', function (): void {
    // The regression that would hurt most: the mask must not swallow real API
    // errors, or the frontend loses every meaningful failure response.
    Route::get('/api/v1/boom', fn () => throw new RuntimeException('api failure'));

    $this->withoutExceptionHandling()
        ->getJson('/api/v1/boom');
})->throws(RuntimeException::class, 'api failure');

it('keeps domain exceptions rendering as JSON under lockdown', function (): void {
    $component = Component::factory()->create();
    $product = Product::factory()->published()->for($component)->create();
    $tier = SubscriptionTier::factory()->create();
    TierAllocation::factory()->metered(0)->create([
        'tier_id' => $tier->id,
        'component_id' => $component->id,
    ]);

    $user = User::factory()->create();
    $user->assignRole(User::SUBSCRIBER);
    Subscription::factory()->for($user)->create(['tier_id' => $tier->id]);

    $this->actingAs($user)
        ->getJson(route('api.products.show', $product))
        ->assertForbidden()
        ->assertJsonPath('code', 'quota_exhausted');
});
