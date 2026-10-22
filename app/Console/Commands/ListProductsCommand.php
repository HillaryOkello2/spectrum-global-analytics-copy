<?php

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Look at what the pipeline has actually written, from the shell.
 *
 * The admin portal shows this too, but a product that has not reached the
 * proofreading board is exactly the one you cannot see there — which is the
 * case worth inspecting when a run has gone wrong. This reads the products
 * table directly and says where each document is in the lifecycle, including
 * the ones no portal view lists.
 *
 * Read-only.
 */
class ListProductsCommand extends Command
{
    protected $signature = 'products:list
        {--days=1 : How many days back to look, by creation date}
        {--component= : Limit to one component code, e.g. DB}
        {--status= : Limit to one product status, e.g. published}
        {--show= : Print one product in full by code or publicId, instead of listing}
        {--out= : With --show, write the document to this file instead of the screen}';

    protected $description = 'List the products generated recently, and where each one has got to';

    public function handle(): int
    {
        if ($this->option('show')) {
            return $this->showOne((string) $this->option('show'));
        }

        $since = now()->subDays((int) $this->option('days'))->startOfDay();

        $products = Product::query()
            ->with(['component', 'generationTask'])
            ->where('created_at', '>=', $since)
            ->when($this->option('component'), fn ($query, string $code) => $query->whereHas(
                'component',
                fn ($component) => $component->where('code', $code),
            ))
            ->when($this->option('status'), fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('created_at')
            ->get();

        if ($products->isEmpty()) {
            $this->components->warn("No products created since {$since->toDateString()}.");
            $this->line('  Nothing was written. Check: php artisan generation:diagnose');

            return self::SUCCESS;
        }

        $this->components->info("Products created since {$since->toDateString()}");

        $this->table(
            ['Code', 'Component', 'Title', 'Words', 'Status', 'Where it is'],
            $products->map(fn (Product $product) => [
                $product->code,
                $product->component?->code ?? '—',
                str($product->title ?? '')->limit(38),
                $product->word_count ?: '—',
                $product->status->value,
                $this->whereItIs($product),
            ])->all(),
        );

        $written = $products->filter(fn (Product $product) => filled($product->body));

        $this->line("  {$written->count()} of {$products->count()} have a written document.");
        $this->line('  Read one in full with: php artisan products:list --show='.($written->first()?->code ?? 'CODE'));

        return self::SUCCESS;
    }

    /**
     * The lifecycle in words, because a status alone does not say whether
     * anyone can see the thing.
     */
    private function whereItIs(Product $product): string
    {
        if ($product->is_hidden && $product->status === ProductStatus::Published) {
            return 'published but hidden in the vault';
        }

        return match ($product->status) {
            ProductStatus::Draft => filled($product->body)
                ? 'written, waiting on its audit'
                : 'reserved, nothing written yet',
            ProductStatus::QaFailed => 'held by the QA gate — not on the board',
            ProductStatus::AwaitingProofreading => 'ON THE BOARD, waiting to be picked up',
            ProductStatus::InProofreading => 'with a proofreader',
            ProductStatus::AwaitingRedaction => 'proofread, waiting on redaction',
            ProductStatus::Rejected => 'rejected by a proofreader',
            ProductStatus::Approved => 'approved, in the FIFO release queue',
            ProductStatus::Published => 'LIVE in the catalogue',
        };
    }

    /**
     * One document in full. The body is the thing you actually want to judge
     * after a run, and no listing can show it.
     */
    private function showOne(string $key): int
    {
        $product = Product::with(['component', 'generationTask'])
            ->where('code', $key)
            ->orWhere('public_id', $key)
            ->first();

        if ($product === null) {
            $this->components->error("No product with the code or publicId [{$key}].");

            return self::FAILURE;
        }

        $task = $product->generationTask;

        $header = implode("\n", array_filter([
            $product->code.' — '.$product->component?->name,
            'Title:    '.$product->title,
            'Byline:   '.$product->byline,
            'Status:   '.$product->status->value.' ('.$this->whereItIs($product).')',
            'Words:    '.($product->word_count ?: 0),
            'Created:  '.$product->created_at?->toDateTimeString(),
            $task === null ? null : 'QA:       '.($task->qa_verdict?->value ?? 'not audited')
                .' after '.$task->qa_round.' revision(s)',
            $product->published_at === null ? null : 'Published: '.$product->published_at->toDateTimeString(),
        ]));

        $document = $header."\n\n".str_repeat('=', 70)."\n\nABSTRACT\n\n"
            .($product->abstract ?? '(none — the proofreader writes this)')
            ."\n\n".str_repeat('=', 70)."\n\n"
            .($product->body ?: '(nothing written)');

        if ($out = $this->option('out')) {
            file_put_contents($out, $document);
            $this->components->info("Written to {$out} (".number_format(strlen($document)).' bytes).');

            return self::SUCCESS;
        }

        $this->line($document);

        return self::SUCCESS;
    }
}
