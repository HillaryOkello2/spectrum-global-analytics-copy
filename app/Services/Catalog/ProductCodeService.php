<?php

namespace App\Services\Catalog;

use App\Enums\Frequency;
use App\Models\Component;
use App\Models\Product;
use App\Models\Topic;
use Carbon\CarbonInterface;

/**
 * Allocates a product's human-readable code: SGA.{component}.{period}.{seq},
 * e.g. `SGA.A4.2026-08.017`.
 *
 * The period comes from the originating Topic's cadence — quarterly series read
 * better as `2026-Q3` than as a month — and the sequence restarts within each
 * component + period, so a code says at a glance which series a product belongs
 * to and where it sits in that period's run.
 */
class ProductCodeService
{
    public const PREFIX = 'SGA';

    private const SEQUENCE_PADDING = 3;

    /**
     * Allocate the next code for a component. MUST be called inside the same
     * transaction as the product insert: the row lock on the current maximum is
     * what stops two concurrent generation jobs claiming the same sequence, and
     * it is only held until that transaction commits. The unique index on
     * `products.code` is the backstop if a caller forgets.
     */
    public function allocate(Component $component, ?Topic $topic = null, ?CarbonInterface $at = null): string
    {
        $prefix = sprintf('%s.%s.%s.', self::PREFIX, $component->code, $this->period($topic, $at ?? now()));

        return $prefix.str_pad((string) ($this->lastSequence($prefix) + 1), self::SEQUENCE_PADDING, '0', STR_PAD_LEFT);
    }

    /**
     * Highest sequence already issued under this prefix. Ordering by length then
     * value keeps 100 above 99 once a period runs past the padding width.
     */
    private function lastSequence(string $prefix): int
    {
        $latest = Product::query()
            ->where('code', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(code) DESC')
            ->orderBy('code', 'desc')
            ->lockForUpdate()
            ->value('code');

        return $latest === null ? 0 : (int) substr($latest, strlen($prefix));
    }

    private function period(?Topic $topic, CarbonInterface $at): string
    {
        return $topic?->frequency === Frequency::Quarterly
            ? $at->format('Y').'-Q'.$at->quarter
            : $at->format('Y-m');
    }
}
