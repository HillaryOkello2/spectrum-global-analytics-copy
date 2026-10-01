<?php

use App\Models\Product;
use App\Models\ProductRating;
use App\Models\ProductRead;
use App\Models\User;

function statsSubscriber(): User
{
    $subscriber = User::factory()->create();
    $subscriber->assignRole(User::SUBSCRIBER);

    return $subscriber;
}

it('reports a subscriber their own reading and rating totals', function (): void {
    $subscriber = statsSubscriber();
    $first = Product::factory()->published()->create();
    $second = Product::factory()->published()->create();

    // The same article twice: two reads, one article read.
    ProductRead::factory()->count(2)->for($first)->for($subscriber)->create();
    ProductRead::factory()->for($second)->for($subscriber)->create();
    // Somebody else's reading never counts toward theirs.
    ProductRead::factory()->for($first)->for(User::factory())->create();

    ProductRating::factory()->for($first)->for($subscriber)->create(['stars' => 5]);
    ProductRating::factory()->for($second)->for($subscriber)->create(['stars' => 4]);

    $this->actingAs($subscriber)
        ->getJson(route('api.me.stats'))
        ->assertOk()
        ->assertJsonPath('data.articlesRead', 2)
        ->assertJsonPath('data.reads', 3)
        ->assertJsonPath('data.ratingsGiven', 2)
        ->assertJsonPath('data.averageRatingGiven', 4.5);
});

it('reports nothing read as zero, and no average at all', function (): void {
    $this->actingAs(statsSubscriber())
        ->getJson(route('api.me.stats'))
        ->assertOk()
        ->assertJsonPath('data.articlesRead', 0)
        ->assertJsonPath('data.reads', 0)
        ->assertJsonPath('data.ratingsGiven', 0)
        ->assertJsonPath('data.averageRatingGiven', null);
});

it('is a subscriber endpoint, closed to staff', function (): void {
    // Catalogue-wide figures are behind /admin/analytics and `view analytics`.
    $staff = User::factory()->create();
    $staff->assignRole(User::ADMIN);

    $this->actingAs($staff)
        ->getJson(route('api.me.stats'))
        ->assertForbidden();
});
