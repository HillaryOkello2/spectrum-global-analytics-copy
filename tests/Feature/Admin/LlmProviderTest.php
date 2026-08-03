<?php

use App\Models\Component;
use App\Models\LlmProvider;
use App\Models\User;
use Database\Seeders\ComponentSeeder;
use Database\Seeders\LlmProviderSeeder;

function adminUser(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

it('returns the component-to-LLM assignment map for admins', function (): void {
    $this->seed([LlmProviderSeeder::class, ComponentSeeder::class]);

    $response = $this->actingAs(adminUser())
        ->getJson(route('api.admin.llm-providers.index'))
        ->assertOk()
        ->assertJsonCount(6, 'data')
        ->assertJsonStructure([
            'data' => [
                ['publicId', 'name', 'vendor', 'modelId', 'isActive', 'components' => [['publicId', 'name', 'code']]],
            ],
        ]);

    // Every seeded component appears under exactly one provider (permanent 1:1 binding).
    $mappedComponentCodes = collect($response->json('data'))
        ->flatMap(fn ($provider) => collect($provider['components'])->pluck('code'))
        ->sort()
        ->values();

    expect($mappedComponentCodes->count())->toBe(Component::count())
        ->and($mappedComponentCodes->unique()->count())->toBe(Component::count());
});

it('forbids subscribers from viewing the LLM map', function (): void {
    LlmProvider::factory()->create();

    $subscriber = User::factory()->create();
    $subscriber->assignRole('subscriber');

    $this->actingAs($subscriber)
        ->getJson(route('api.admin.llm-providers.index'))
        ->assertForbidden();
});

it('rejects unauthenticated access', function (): void {
    $this->getJson(route('api.admin.llm-providers.index'))->assertUnauthorized();
});
