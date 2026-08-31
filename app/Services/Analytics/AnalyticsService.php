<?php

namespace App\Services\Analytics;

use App\Enums\ProductStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TaskStatus;
use App\Models\Component;
use App\Models\GenerationTask;
use App\Models\Product;
use App\Models\ProductRead;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Query layer behind the admin dashboard charts.
 *
 * Note on counts: aggregates come back as strings on MySQL and as ints on the
 * SQLite the suite runs against, so every aggregate is cast explicitly here
 * rather than at the call site.
 */
class AnalyticsService
{
    private const DEFAULT_LIMIT = 10;

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        return [
            'totalSubscribers' => User::role(User::SUBSCRIBER)->count(),
            'activeSubscriptions' => Subscription::active()->count(),
            'productsPublished' => Product::where('status', ProductStatus::Published)->count(),
            'pendingProofreading' => GenerationTask::whereIn('status', [
                TaskStatus::AwaitingProofreading,
                TaskStatus::InProofreading,
            ])->count(),
        ];
    }

    public function subscriptionsByTier(): Collection
    {
        return SubscriptionTier::query()
            ->withCount(['subscriptions as active_count' => fn ($q) => $q->where('status', SubscriptionStatus::Active)])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SubscriptionTier $tier) => [
                'tier' => $tier->name,
                'activeSubscriptions' => (int) $tier->active_count,
            ]);
    }

    public function productsByComponent(): Collection
    {
        return Component::query()
            ->withCount(['products as products_published_count' => fn ($q) => $q->where('status', ProductStatus::Published)])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Component $component) => [
                'component' => $component->name,
                'code' => $component->code,
                // Deliberately counts published products including vault-hidden
                // ones: hiding is a subscriber-facing control, not a measure of
                // editorial output. This will not match the catalogue's
                // `productsCount`, which is visible-only.
                'productsPublished' => (int) $component->products_published_count,
            ]);
    }

    /**
     * Subscribers grouped by their registered country.
     *
     * `users.country` is free text, so values are grouped as stored. Anything
     * blank is bucketed as "Unknown" rather than dropped, so the totals still
     * add up to the subscriber count on the summary tile.
     */
    public function subscribersByLocation(): Collection
    {
        return User::query()
            ->role(User::SUBSCRIBER)
            ->selectRaw('country, COUNT(*) as subscriber_count')
            ->groupBy('country')
            ->orderByDesc('subscriber_count')
            ->orderBy('country')
            ->get()
            ->map(fn (User $row) => [
                'country' => filled($row->country) ? $row->country : 'Unknown',
                'subscribers' => (int) $row->subscriber_count,
            ]);
    }

    /**
     * Most-read products, optionally within a window.
     *
     * With no window this reads the denormalised counter; with one it counts the
     * ledger, since the counter is all-time only.
     */
    public function mostReadProducts(?string $from = null, ?string $to = null, ?int $limit = null): Collection
    {
        $limit ??= self::DEFAULT_LIMIT;

        if ($from === null && $to === null) {
            return Product::query()
                ->with('component')
                ->where('reads_count', '>', 0)
                ->orderByDesc('reads_count')
                ->limit($limit)
                ->get()
                ->map(fn (Product $product) => $this->readRow($product, $product->reads_count));
        }

        $counts = ProductRead::query()
            ->selectRaw('product_id, COUNT(*) as read_count')
            ->between($from, $to)
            ->groupBy('product_id')
            ->orderByDesc('read_count')
            ->limit($limit)
            ->pluck('read_count', 'product_id');

        return Product::query()
            ->with('component')
            ->whereIn('id', $counts->keys())
            ->get()
            ->map(fn (Product $product) => $this->readRow($product, (int) $counts[$product->id]))
            ->sortByDesc('reads')
            ->values();
    }

    /**
     * Rated products, best first. Products nobody has rated are excluded —
     * a zero average would otherwise sink them below genuinely poor ratings.
     */
    public function productRatings(?int $limit = null): Collection
    {
        return Product::query()
            ->with('component')
            ->withCount('ratings')
            ->withAvg('ratings', 'stars')
            // whereHas, not having(): withCount adds a select subquery rather
            // than a real aggregate, so HAVING has nothing to attach to.
            ->whereHas('ratings')
            ->orderByDesc('ratings_avg_stars')
            ->limit($limit ?? self::DEFAULT_LIMIT)
            ->get()
            ->map(fn (Product $product) => [
                'publicId' => $product->public_id,
                'code' => $product->code,
                'title' => $product->title,
                'component' => $product->component?->code,
                'averageRating' => round((float) $product->ratings_avg_stars, 2),
                'ratingsCount' => (int) $product->ratings_count,
            ]);
    }

    /**
     * Rejections per component, plus the most recent rejection notes.
     *
     * Rejection lives on the generation task, not the product — a rejected task
     * can be reopened, so the note is the editorial record of why.
     *
     * @return array{byComponent: Collection, recent: Collection}
     */
    public function rejections(?int $limit = null): array
    {
        $byComponent = Component::query()
            ->withCount(['products as rejected_count' => fn ($q) => $q->where('status', ProductStatus::Rejected)])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Component $component) => [
                'component' => $component->name,
                'code' => $component->code,
                'rejected' => (int) $component->rejected_count,
            ]);

        $recent = GenerationTask::query()
            ->with(['product.component', 'topic.component'])
            ->where('status', TaskStatus::Rejected)
            // No rejected_at column: the task is only ever written on transition,
            // so updated_at is the rejection time.
            ->latest('updated_at')
            ->limit($limit ?? self::DEFAULT_LIMIT)
            ->get()
            ->map(fn (GenerationTask $task) => [
                'taskPublicId' => $task->public_id,
                'productCode' => $task->product?->code,
                'title' => $task->product?->title ?? $task->topic?->title,
                'component' => ($task->product?->component ?? $task->topic?->component)?->code,
                'note' => $task->rejection_note,
                'rejectedAt' => $task->updated_at?->format('Y-m-d H:i:s'),
            ]);

        return ['byComponent' => $byComponent, 'recent' => $recent];
    }

    /**
     * @return array<string, mixed>
     */
    private function readRow(Product $product, int $reads): array
    {
        return [
            'publicId' => $product->public_id,
            'code' => $product->code,
            'title' => $product->title,
            'component' => $product->component?->code,
            'reads' => $reads,
        ];
    }
}
