<?php

namespace Database\Factories;

use App\Enums\AccessType;
use App\Models\Component;
use App\Models\SubscriptionTier;
use App\Models\TierAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TierAllocation>
 */
class TierAllocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tier_id' => SubscriptionTier::factory(),
            'component_id' => Component::factory(),
            'access_type' => AccessType::Unlimited,
            'monthly_limit' => null,
        ];
    }

    public function metered(int $limit): static
    {
        return $this->state(fn (array $attributes) => [
            'access_type' => AccessType::Metered,
            'monthly_limit' => $limit,
        ]);
    }

    public function denied(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_type' => AccessType::Denied,
            'monthly_limit' => null,
        ]);
    }
}
