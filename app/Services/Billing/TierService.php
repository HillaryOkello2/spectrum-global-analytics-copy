<?php

namespace App\Services\Billing;

use App\Exceptions\Domain\DefaultFreeTierPriceLockedException;
use App\Models\Component;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Edits to the commercial terms of a tier (FR-41).
 *
 * A tier is read on every signup, renewal and upgrade, so a change here is a
 * change to what the next payment collects — never to a term already paid for.
 * Existing subscriptions keep running on the price they were charged until they
 * renew, which is why nothing in here touches the subscriptions table.
 */
class TierService
{
    /**
     * Replace what a tier unlocks, component by component.
     *
     * The submission is the whole matrix: a component left out of it loses its
     * allocation, and no allocation means denied (EntitlementService treats a
     * missing row and an explicit `denied` identically).
     *
     * @param  array<int, array{component: string, access_type: string, monthly_limit?: int|null}>  $allocations
     */
    public function syncAllocations(SubscriptionTier $tier, array $allocations, User $actor): SubscriptionTier
    {
        return DB::transaction(function () use ($tier, $allocations, $actor) {
            $before = $this->matrixOf($tier);

            $components = Component::query()
                ->whereIn('public_id', array_column($allocations, 'component'))
                ->orWhereIn('code', array_column($allocations, 'component'))
                ->get();

            $keep = [];

            foreach ($allocations as $row) {
                $component = $components->first(
                    fn (Component $component) => $component->public_id === $row['component']
                        || $component->code === $row['component'],
                );

                $tier->allocations()->updateOrCreate(
                    ['component_id' => $component->id],
                    [
                        'access_type' => $row['access_type'],
                        // Null unless metered: the request rules already refuse
                        // a limit that contradicts the access type.
                        'monthly_limit' => $row['monthly_limit'] ?? null,
                    ],
                );

                $keep[] = $component->id;
            }

            // Whatever was not submitted is no longer unlocked.
            $tier->allocations()->whereNotIn('component_id', $keep)->delete();

            $tier->load('allocations.component');

            Audit::of($tier)->by($actor)
                ->change('allocations', $before, $this->matrixOf($tier), 'Component access')
                ->log('tier allocations updated');

            return $tier;
        });
    }

    /**
     * The matrix as `CODE => access` / `CODE => access(limit)`, for the audit
     * log — readable in a way a list of ids is not.
     *
     * @return array<string, string>
     */
    private function matrixOf(SubscriptionTier $tier): array
    {
        return $tier->allocations()->with('component')->get()
            ->mapWithKeys(fn ($allocation) => [
                $allocation->component->code => $allocation->monthly_limit === null
                    ? $allocation->access_type->value
                    : $allocation->access_type->value.'('.$allocation->monthly_limit.')',
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(SubscriptionTier $tier, array $attributes, User $actor): SubscriptionTier
    {
        return DB::transaction(function () use ($tier, $attributes, $actor) {
            $this->assertDefaultFreeRules($tier, $attributes);

            $before = $tier->only(array_keys($attributes));

            if (($attributes['featured'] ?? false) === true) {
                SubscriptionTier::query()
                    ->where('id', '!=', $tier->id)
                    ->where('featured', true)
                    ->update(['featured' => false]);
            }

            if (($attributes['is_default_free'] ?? false) === true) {
                SubscriptionTier::query()
                    ->where('id', '!=', $tier->id)
                    ->where('is_default_free', true)
                    ->update(['is_default_free' => false]);
            }

            $tier->update($attributes);

            Audit::of($tier)->by($actor)
                ->changes($before, $attributes)
                ->log('subscription tier updated');

            return $tier->refresh()->load('allocations.component');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertDefaultFreeRules(SubscriptionTier $tier, array $attributes): void
    {
        $willBeDefaultFree = array_key_exists('is_default_free', $attributes)
            ? (bool) $attributes['is_default_free']
            : $tier->is_default_free;

        if (! $willBeDefaultFree && ! $tier->is_default_free) {
            return;
        }

        // Still the default free tier after this PATCH (or becoming it).
        if ($willBeDefaultFree) {
            $nextPrice = array_key_exists('price', $attributes) ? $attributes['price'] : $tier->price;

            if ($nextPrice === null || (float) $nextPrice !== 0.0) {
                throw new DefaultFreeTierPriceLockedException;
            }
        }

        // Deactivating the default free tier would leave nowhere for signups
        // and lapsed subscribers to land.
        if ($tier->is_default_free && ($attributes['is_active'] ?? true) === false) {
            throw new DefaultFreeTierPriceLockedException(
                'The default free tier cannot be deactivated.',
            );
        }

        // Clearing the flag with no replacement is refused when this is the
        // only default-free tier — callers must point the flag at another tier.
        if ($tier->is_default_free
            && array_key_exists('is_default_free', $attributes)
            && $attributes['is_default_free'] === false
        ) {
            throw new DefaultFreeTierPriceLockedException(
                'Set is_default_free on another tier before clearing it here.',
            );
        }
    }
}
