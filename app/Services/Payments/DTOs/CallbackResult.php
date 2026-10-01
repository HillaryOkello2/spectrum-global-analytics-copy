<?php

namespace App\Services\Payments\DTOs;

readonly class CallbackResult
{
    /**
     * @param  array<string, mixed>  $raw  Callback payload, persisted for audit.
     * @param  string|null  $amount  What the gateway says was paid; null when it doesn't say.
     * @param  string|null  $transactionCode  The gateway's own receipt, e.g. the M-Pesa code.
     */
    public function __construct(
        public string $gatewayRef,
        public bool $successful,
        public array $raw = [],
        public ?string $amount = null,
        public ?string $transactionCode = null,
    ) {}
}
