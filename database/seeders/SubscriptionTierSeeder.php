<?php

namespace Database\Seeders;

use App\Enums\AccessType;
use App\Models\Component;
use App\Models\SubscriptionTier;
use App\Models\TierAllocation;
use Illuminate\Database\Seeder;

class SubscriptionTierSeeder extends Seeder
{
    /**
     * Tier allocation matrix from Blueprint Annex 3, keyed by component code and
     * trimmed to the nine surviving Components (the 2026-08 scope change dropped
     * A10–A14, and with A14 the pay-to-own tier line).
     *
     * Prices are placeholders — pricing is client-supplied configuration
     * (Assumptions §21). Freemium must stay 0 (it activates without payment).
     */
    private const TIERS = [
        'Freemium' => [
            'price' => 0,
            'allocations' => [
                'A1' => ['metered', 10],
                'A2' => ['metered', 3],
                'A3' => ['metered', 2],
                'A4' => ['denied'], 'A5' => ['denied'], 'A6' => ['denied'], 'A7' => ['denied'],
                'A8' => ['denied'], 'A9' => ['denied'],
            ],
        ],
        'Premium' => [
            'price' => 49.99,
            'allocations' => [
                'A1' => ['unlimited'],
                'A2' => ['unlimited'],
                'A3' => ['metered', 5],
                'A4' => ['unlimited'],
                'A5' => ['unlimited'],
                'A8' => ['metered', 2],
                'A9' => ['metered', 2],
                'A6' => ['denied'], 'A7' => ['denied'],
            ],
        ],
        'Superior' => [
            'price' => 99.99,
            'allocations' => [
                'A1' => ['unlimited'], 'A2' => ['unlimited'], 'A3' => ['unlimited'],
                'A4' => ['unlimited'], 'A5' => ['unlimited'],
                'A6' => ['unlimited'],
                'A7' => ['unlimited'],
                'A8' => ['metered', 10],
                'A9' => ['metered', 10],
            ],
        ],
        'Platinum' => [
            'price' => 199.99,
            'allocations' => [
                'A1' => ['unlimited'], 'A2' => ['unlimited'], 'A3' => ['unlimited'],
                'A4' => ['unlimited'], 'A5' => ['unlimited'], 'A6' => ['unlimited'],
                'A7' => ['unlimited'], 'A8' => ['unlimited'], 'A9' => ['unlimited'],
            ],
        ],
    ];

    public function run(): void
    {
        $sortOrder = 0;

        foreach (self::TIERS as $name => $definition) {
            $tier = SubscriptionTier::updateOrCreate(['name' => $name], [
                'price' => $definition['price'],
                'currency' => 'USD',
                'billing_period' => 'monthly',
                'is_active' => true,
                'sort_order' => ++$sortOrder,
            ]);

            Component::all()->each(function (Component $component) use ($tier, $definition): void {
                [$accessType, $limit] = [...$definition['allocations'][$component->code], null];

                TierAllocation::updateOrCreate(
                    ['tier_id' => $tier->id, 'component_id' => $component->id],
                    [
                        'access_type' => AccessType::from($accessType),
                        'monthly_limit' => $limit,
                    ],
                );
            });
        }
    }
}
