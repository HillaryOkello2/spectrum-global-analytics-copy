<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Publishing\ReleaseService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ReleaseApprovedProductsCommand extends Command
{
    protected $signature = 'products:release {--limit= : Override the configured batch size}';

    protected $description = 'Publish approved products oldest-approval-first (FIFO release)';

    /**
     * @param  Collection<int, Product>  $products
     */
    private function report(Collection $products, string $why, string $remedy): void
    {
        if ($products->isEmpty()) {
            return;
        }

        $this->warn("{$products->count()} approved product(s) {$why}:");

        foreach ($products->take(10) as $product) {
            $this->warn("  {$product->code}");
        }

        $this->warn($remedy);
    }

    public function handle(ReleaseService $releases): int
    {
        $limit = $this->option('limit');

        $released = $releases->releaseBatch($limit === null ? null : (int) $limit);

        $this->info("Released {$released->count()} product(s).");

        foreach ($released as $product) {
            $this->line("  {$product->code}  {$product->title}");
        }

        $blocked = $releases->blocked();

        $this->report(
            $blocked['abstract'],
            'held back with no abstract',
            'Run `php artisan products:backfill-abstracts` to derive one from the body.',
        );

        $this->report(
            $blocked['unproofread'],
            'held back because no proofreader has submitted the document',
            'Open the task on the board and submit the proofread; raw model output is never published.',
        );

        return self::SUCCESS;
    }
}
