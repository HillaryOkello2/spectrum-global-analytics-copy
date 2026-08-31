<?php

namespace App\Services\Entitlement;

use App\Enums\AccessType;
use App\Enums\EntitlementLevel;
use App\Models\Product;
use App\Models\TierAllocation;
use App\Models\User;

/**
 * Single choke point for tier-based content access (FR-18, Annex 3, R-04).
 * Every endpoint that returns a Product body must consult this service.
 */
class EntitlementService
{
    /**
     * Resolve the caller's access level for a Product. When the allocation is
     * metered and quota remains, this CONSUMES one unit (idempotently per
     * user+product — re-reading an unlocked product never double-charges).
     */
    public function check(?User $user, Product $product): EntitlementResult
    {
        return $this->withRedactedFallback($this->resolve($user, $product), $product);
    }

    private function resolve(?User $user, Product $product): EntitlementResult
    {
        // Visitors (and any non-subscriber) only ever see the abstract (FR-09).
        if ($user === null) {
            return new EntitlementResult(EntitlementLevel::PreviewOnly);
        }

        // Pay-to-own components are never covered by a flat tier (Annex 3). No
        // seeded component is transactional today; the path stays wired for when
        // one returns.
        if ($product->component->is_transactional) {
            return $this->checkTransactional($user, $product);
        }

        $subscription = $user->activeSubscription;

        if ($subscription === null) {
            return new EntitlementResult(EntitlementLevel::PreviewOnly);
        }

        $allocation = TierAllocation::query()
            ->where('tier_id', $subscription->tier_id)
            ->where('component_id', $product->component_id)
            ->first();

        return match ($allocation?->access_type) {
            AccessType::Unlimited => new EntitlementResult(EntitlementLevel::FullAccess, AccessType::Unlimited),
            AccessType::Metered => $this->checkMetered($user, $product, $allocation),
            default => new EntitlementResult(EntitlementLevel::Denied, AccessType::Denied),
        };
    }

    /**
     * A subscriber the tier withholds the full document from still gets the
     * redacted one, where a redaction has been proofread and approved. Visitors
     * never do — they are outside the subscription entirely and stop at the
     * abstract.
     */
    private function withRedactedFallback(EntitlementResult $result, Product $product): EntitlementResult
    {
        $withheld = in_array($result->level, [EntitlementLevel::Denied, EntitlementLevel::MeteredExhausted], true);

        if (! $withheld || ! $product->redaction_approved || blank($product->redacted_body)) {
            return $result;
        }

        return $result->redacted();
    }

    private function checkTransactional(User $user, Product $product): EntitlementResult
    {
        $purchased = $user->purchases()
            ->where('product_id', $product->id)
            ->exists();

        return $purchased
            ? new EntitlementResult(EntitlementLevel::FullAccess)
            : new EntitlementResult(EntitlementLevel::PreviewOnly);
    }

    private function checkMetered(User $user, Product $product, TierAllocation $allocation): EntitlementResult
    {
        // Already unlocked this product — free re-read, no quota charge.
        $alreadyConsumed = $user->consumptions()
            ->where('product_id', $product->id)
            ->exists();

        // Quota is per component per calendar month: "DB max 10/month". With
        // Components now global there is exactly one A1, so this is a plain
        // component_id match.
        $usedThisMonth = fn (): int => $user->consumptions()
            ->where('component_id', $product->component_id)
            ->whereBetween('consumed_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        if ($alreadyConsumed) {
            return new EntitlementResult(
                EntitlementLevel::FullAccess, AccessType::Metered, $usedThisMonth(), $allocation->monthly_limit,
            );
        }

        $used = $usedThisMonth();

        if ($used >= $allocation->monthly_limit) {
            return new EntitlementResult(
                EntitlementLevel::MeteredExhausted, AccessType::Metered, $used, $allocation->monthly_limit,
            );
        }

        // firstOrCreate + unique(user_id, product_id) index keeps concurrent
        // requests from double-charging the quota.
        $user->consumptions()->firstOrCreate(
            ['product_id' => $product->id],
            ['component_id' => $product->component_id, 'consumed_at' => now()],
        );

        return new EntitlementResult(
            EntitlementLevel::FullAccess, AccessType::Metered, $used + 1, $allocation->monthly_limit,
        );
    }
}
