<?php

namespace App\Jobs\Middleware;

use App\Exceptions\EmptyLlmResponseException;
use Illuminate\Queue\Middleware\FailOnException;
use Throwable;

/**
 * Fails an LLM job outright, with no retry, when the model ran out of tokens
 * before writing anything.
 *
 * That failure is deterministic — the same prompt, model and ceiling produce
 * it every time — so the job's usual retries only buy the same wasted output
 * tokens twice more. A CC brief on DeepSeek V4 Pro spent all 8,000 tokens
 * reasoning; on a real worker, three attempts would have been 24,000.
 * Everything else (timeouts, 5xx, rate limits) still retries as before.
 */
class FailOnTokenCeiling extends FailOnException
{
    public function __construct()
    {
        parent::__construct(
            fn (Throwable $e): bool => $e instanceof EmptyLlmResponseException && $e->hitTokenCeiling(),
        );
    }
}
