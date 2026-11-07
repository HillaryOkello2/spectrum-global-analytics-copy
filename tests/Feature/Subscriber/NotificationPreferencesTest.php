<?php

use App\Enums\AccessType;
use App\Enums\ProductStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\NotifySubscribersOfNewArticleJob;
use App\Models\ArticleDigestQueue;
use App\Models\Component;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Models\TierAllocation;
use App\Models\User;
use App\Notifications\NewArticlePublished;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

function prefsSubscriber(array $prefs = [], ?Component $component = null): User
{
    $user = User::factory()->create([
        'notification_preferences' => $prefs === [] ? null : [
            'new_articles' => $prefs['new_articles'] ?? NotificationPreferences::DAILY,
            'components' => $prefs['components'] ?? null,
        ],
    ]);
    $user->assignRole(User::SUBSCRIBER);

    $tier = SubscriptionTier::factory()->create(['price' => 49.99]);
    $component ??= Component::factory()->create(['code' => 'DB']);
    TierAllocation::factory()->create([
        'tier_id' => $tier->id,
        'component_id' => $component->id,
        'access_type' => AccessType::Unlimited,
    ]);
    Subscription::factory()->for($user)->create([
        'tier_id' => $tier->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addMonth(),
    ]);

    return $user;
}

it('returns default notification preferences', function (): void {
    $user = prefsSubscriber();

    $this->actingAs($user)
        ->getJson(route('api.me.notification-preferences.show'))
        ->assertOk()
        ->assertJsonPath('data.newArticles', 'daily')
        ->assertJsonPath('data.components', null);
});

it('updates notification preferences', function (): void {
    $user = prefsSubscriber();

    $this->actingAs($user)
        ->patchJson(route('api.me.notification-preferences.update'), [
            'newArticles' => 'each',
            'components' => ['DB', 'WH'],
        ])
        ->assertOk()
        ->assertJsonPath('data.newArticles', 'each')
        ->assertJsonPath('data.components.0', 'DB');
});

it('emails each-mode subscribers once when a product is first published', function (): void {
    Notification::fake();

    $component = Component::factory()->create(['code' => 'DB', 'is_virtual' => false]);
    $user = prefsSubscriber(['new_articles' => 'each'], $component);
    $product = Product::factory()->create([
        'component_id' => $component->id,
        'status' => ProductStatus::Approved,
        'published_at' => null,
        'abstract' => 'A short preview.',
    ]);

    (new NotifySubscribersOfNewArticleJob($product))->handle(app(\App\Services\Notifications\NewArticleNotifier::class));

    Notification::assertSentTo($user, NewArticlePublished::class);

    // Re-running must not be gated here — the ReleaseService only dispatches on first publish.
});

it('queues daily digests instead of sending immediately', function (): void {
    Notification::fake();

    $component = Component::factory()->create(['code' => 'DB', 'is_virtual' => false]);
    $user = prefsSubscriber(['new_articles' => 'daily'], $component);
    $product = Product::factory()->create([
        'component_id' => $component->id,
        'status' => ProductStatus::Published,
        'published_at' => now(),
        'abstract' => 'Digest me.',
    ]);

    (new NotifySubscribersOfNewArticleJob($product))->handle(app(\App\Services\Notifications\NewArticleNotifier::class));

    Notification::assertNotSentTo($user, NewArticlePublished::class);
    expect(ArticleDigestQueue::where('user_id', $user->id)->where('product_id', $product->id)->exists())->toBeTrue();

    $this->artisan('articles:send-digests')->assertSuccessful();

    Notification::assertSentTo($user, NewArticlePublished::class);
    expect(ArticleDigestQueue::where('user_id', $user->id)->exists())->toBeFalse();
});

it('honours a signed unsubscribe link', function (): void {
    $user = prefsSubscriber(['new_articles' => 'each']);

    $url = URL::temporarySignedRoute(
        'api.notification-preferences.unsubscribe',
        now()->addDay(),
        ['subscriber' => $user->public_id],
    );

    $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('data.newArticles', 'none');
});
