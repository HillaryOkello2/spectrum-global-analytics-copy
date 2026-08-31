<?php

namespace App\Services\Catalog;

use App\Models\Component;
use App\Models\Product;
use Carbon\CarbonInterface;

/**
 * Allocates a product's human-readable code: SGA.{ref}.{seq}.{MM}.{YY},
 * e.g. `SGA.DB.001.08.26`.
 *
 * The format is the client's, not ours — every prompt in the August pack tells
 * the model to print this string as the document's [DOCUMENT_REF]. The stored
 * code and the reference inside the document therefore have to agree, so this
 * allocates the code first and the generator interpolates it into the prompt.
 *
 * `ref` is the component's `ref_code`, which differs from its catalogue `code`
 * for three components (BS→BK, CC→CB, HM→CS). The sequence restarts each month
 * within each component.
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
    public function allocate(Component $component, ?CarbonInterface $at = null): string
    {
        $at ??= now();

        $prefix = sprintf('%s.%s.', self::PREFIX, $component->ref_code);
        $suffix = '.'.$at->format('m.y');

        $sequence = str_pad(
            (string) ($this->lastSequence($prefix, $suffix) + 1),
            self::SEQUENCE_PADDING,
            '0',
            STR_PAD_LEFT
        );

        return $prefix.$sequence.$suffix;
    }

    /**
     * Highest sequence already issued for this component in this month.
     *
     * The sequence sits in the middle of the code rather than at the end, but
     * the suffix is a constant here, so ordering by length then value still
     * sorts by sequence — length first keeps 1000 above 999 once a month runs
     * past the padding width.
     */
    private function lastSequence(string $prefix, string $suffix): int
    {
        $latest = Product::query()
            ->where('code', 'like', $prefix.'%'.$suffix)
            ->orderByRaw('LENGTH(code) DESC')
            ->orderBy('code', 'desc')
            ->lockForUpdate()
            ->value('code');

        if ($latest === null) {
            return 0;
        }

        return (int) substr($latest, strlen($prefix), -strlen($suffix));
    }
}
