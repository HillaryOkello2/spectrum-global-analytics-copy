<?php

use App\Models\Component;
use App\Models\Product;

it('counts the words of a body wherever the body is written', function (): void {
    $product = Product::factory()->create(['body' => 'one two three four five']);

    expect($product->word_count)->toBe(5);

    $product->update(['body' => trim(str_repeat('word ', 450))]);

    expect($product->refresh()->word_count)->toBe(450);
});

it('estimates reading time on listings and previews, which never carry the body', function (): void {
    $component = Component::factory()->create();
    $product = Product::factory()->for($component)->published()->create([
        'body' => trim(str_repeat('word ', 500)),
    ]);

    // 500 words at 225 a minute, rounded up.
    $this->getJson(route('api.catalog.components.products.index', $component))
        ->assertOk()
        ->assertJsonPath('data.0.wordCount', 500)
        ->assertJsonPath('data.0.readMinutes', 3);

    $this->getJson(route('api.products.preview', $product))
        ->assertOk()
        ->assertJsonPath('data.readMinutes', 3)
        // The estimate, never the words it was counted from.
        ->assertJsonMissingPath('data.body');
});

it('never reports under a minute for an article with any words at all', function (): void {
    $product = Product::factory()->published()->create(['body' => 'A short note.']);

    $this->getJson(route('api.products.preview', $product))
        ->assertOk()
        ->assertJsonPath('data.readMinutes', 1);
});
