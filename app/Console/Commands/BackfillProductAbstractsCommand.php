<?php

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\Generation\AbstractExtractor;
use Illuminate\Console\Command;

/**
 * Derives the abstract for products that reached approval without one.
 *
 * The FIFO release queue skips any product whose abstract is null — it is the
 * public preview, so a product without one cannot go live — and says nothing
 * about why. This fills them in from the body, which is where the abstract is
 * taken from in the first place.
 */
class BackfillProductAbstractsCommand extends Command
{
    protected $signature = 'products:backfill-abstracts {--dry-run : Report what would be filled, change nothing}';

    protected $description = 'Derive missing abstracts so approved products can be released';

    public function handle(AbstractExtractor $abstracts): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $products = Product::query()
            ->whereNull('abstract')
            ->whereIn('status', [
                ProductStatus::AwaitingRedaction,
                ProductStatus::Approved,
                ProductStatus::Published,
            ])
            ->orderBy('id')
            ->get();

        $filled = 0;
        $stuck = 0;

        foreach ($products as $product) {
            $abstract = $abstracts->extract((string) $product->body);

            if ($abstract === null) {
                $this->warn("  {$product->code}  no prose could be lifted from the body — needs a human");
                $stuck++;

                continue;
            }

            if (! $dryRun) {
                $product->update(['abstract' => $abstract]);
            }

            $this->line("  {$product->code}  ".str($abstract)->limit(70));
            $filled++;
        }

        $this->info($dryRun
            ? "{$filled} product(s) would be filled, {$stuck} could not be."
            : "Filled {$filled} abstract(s), {$stuck} could not be.");

        return self::SUCCESS;
    }
}
