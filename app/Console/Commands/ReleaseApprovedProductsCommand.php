<?php

namespace App\Console\Commands;

use App\Services\Publishing\ReleaseService;
use Illuminate\Console\Command;

class ReleaseApprovedProductsCommand extends Command
{
    protected $signature = 'products:release {--limit= : Override the configured batch size}';

    protected $description = 'Publish approved products oldest-approval-first (FIFO release)';

    public function handle(ReleaseService $releases): int
    {
        $limit = $this->option('limit');

        $released = $releases->releaseBatch($limit === null ? null : (int) $limit);

        $this->info("Released {$released->count()} product(s).");

        foreach ($released as $product) {
            $this->line("  {$product->code}  {$product->title}");
        }

        return self::SUCCESS;
    }
}
