<?php

use App\Models\Component;
use App\Models\User;
use Database\Seeders\ComponentSeeder;
use Database\Seeders\LlmProviderSeeder;

it('returns a color key on every seeded catalogue component', function (): void {
    $this->seed([LlmProviderSeeder::class, ComponentSeeder::class]);

    $response = $this->getJson(route('api.catalog.components.index'))->assertOk();

    $colors = collect($response->json('data'))->pluck('color');

    expect($colors->filter()->isNotEmpty())->toBeTrue()
        ->and($colors->filter()->unique()->count())->toBe($colors->filter()->count())
        ->and($colors->every(fn ($c) => $c === null || (is_string($c) && str_starts_with($c, 'component-'))))->toBeTrue();
});

it('lets a vault admin set a component color', function (): void {
    $component = Component::factory()->create(['code' => 'XX', 'color' => 'component-1']);
    $admin = User::factory()->create();
    $admin->assignRole(User::ADMIN);

    $this->actingAs($admin)
        ->patchJson(route('api.admin.vault.components.update', $component), [
            'color' => 'component-11',
        ])
        ->assertOk()
        ->assertJsonPath('data.color', 'component-11');
});
