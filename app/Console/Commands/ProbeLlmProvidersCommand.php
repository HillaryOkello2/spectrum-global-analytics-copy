<?php

namespace App\Console\Commands;

use App\Models\LlmProvider;
use App\Services\Analytics\LlmHealthService;
use App\Services\Llm\LlmManager;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Liveness check for every active provider (§4.2 of the LLM health contract).
 *
 * This is what makes a dead provider show as `down` when nobody has generated
 * a product recently. Without it the dashboard can only report on traffic that
 * happened to occur, so a revoked API key would stay invisible until the next
 * scheduled generation run — potentially a day later.
 *
 * Each probe is one token of output on a one-line prompt, so the running cost
 * is negligible even at a five-minute cadence.
 */
class ProbeLlmProvidersCommand extends Command
{
    protected $signature = 'llm:probe
        {--provider= : Probe one provider by public id, name or model id}
        {--force : Probe even when LLM_PROBE_ENABLED is off}';

    protected $description = 'Send a one-token liveness probe to every active LLM provider';

    public function handle(LlmManager $llm, LlmHealthService $health): int
    {
        if (! config('llm.health.probe.enabled') && ! $this->option('force')) {
            $this->comment('LLM probing is disabled (LLM_PROBE_ENABLED). Use --force to probe anyway.');

            return self::SUCCESS;
        }

        $providers = $this->providers();

        if ($providers->isEmpty()) {
            $this->warn('No active LLM providers to probe.');

            return self::SUCCESS;
        }

        $prompt = (string) config('llm.health.probe.prompt', 'Reply with: OK');
        $failed = 0;

        foreach ($providers as $provider) {
            // The recorder writes the row — success or failure — from inside
            // the client, so there is nothing to persist here. All this loop
            // decides is what to print and what to exit with.
            try {
                $llm->probe($provider)->generate($prompt);

                $this->line("  <info>ok</info>      {$provider->name} ({$provider->vendor})");
            } catch (Throwable $e) {
                $failed++;

                $this->line("  <fg=red>failed</> {$provider->name} ({$provider->vendor}): ".$e->getMessage());
            }
        }

        // The snapshot is cached for a few seconds; a probe that just changed a
        // provider's status should show on the next poll, not after the TTL.
        $health->forget();

        $this->info("Probed {$providers->count()} provider(s), {$failed} failing.");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, LlmProvider>
     */
    private function providers()
    {
        $one = $this->option('provider');

        return LlmProvider::query()
            ->when($one === null, fn ($query) => $query->where('is_active', true))
            ->when($one !== null, fn ($query) => $query->where(
                fn ($q) => $q->where('public_id', $one)->orWhere('name', $one)->orWhere('model_id', $one),
            ))
            ->orderBy('id')
            ->get();
    }
}
