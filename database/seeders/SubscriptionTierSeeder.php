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
     * Tier allocation matrix from Blueprint Annex 3, re-keyed onto the nine
     * components of the client's August prompt pack. The ladder is unchanged in
     * shape — the pulse products (DB/WH/MF) open up first, the long-form research
     * and the sovereign-only lines (CC/HM) last — only the codes moved.
     *
     * Prices are placeholders — pricing is client-supplied configuration
     * (Assumptions §21). Freemium must stay 0 (it activates without payment).
     */
    private const TIERS = [
        'Freemium' => [
            'price' => 0,
            'allocations' => [
                'DB' => ['metered', 10],
                'WH' => ['metered', 3],
                'MF' => ['metered', 2],
                'ES' => ['denied'], 'BS' => ['denied'], 'CC' => ['denied'],
                'RP' => ['denied'], 'WP' => ['denied'], 'HM' => ['denied'],
            ],
        ],
        'Premium' => [
            'price' => 49.99,
            'allocations' => [
                'DB' => ['unlimited'],
                'WH' => ['unlimited'],
                'MF' => ['unlimited'],
                'ES' => ['unlimited'],
                'BS' => ['metered', 5],
                'RP' => ['metered', 2],
                'WP' => ['metered', 2],
                'CC' => ['denied'], 'HM' => ['denied'],
            ],
        ],
        'Superior' => [
            'price' => 99.99,
            'allocations' => [
                'DB' => ['unlimited'], 'WH' => ['unlimited'], 'MF' => ['unlimited'],
                'ES' => ['unlimited'], 'BS' => ['unlimited'],
                'RP' => ['unlimited'],
                'WP' => ['unlimited'],
                'CC' => ['metered', 10],
                'HM' => ['metered', 10],
            ],
        ],
        'Platinum' => [
            'price' => 199.99,
            'allocations' => [
                'DB' => ['unlimited'], 'WH' => ['unlimited'], 'MF' => ['unlimited'],
                'ES' => ['unlimited'], 'BS' => ['unlimited'], 'CC' => ['unlimited'],
                'RP' => ['unlimited'], 'WP' => ['unlimited'], 'HM' => ['unlimited'],
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
