<?php

namespace App\Services\Payments\DTOs;

readonly class CallbackResult
{
    /**
     * @param  string  $gatewayRef  The reference we gave the gateway; may be empty
     *                              when the gateway returns our `meta` instead.
     * @param  array<string, mixed>  $raw  Callback payload, persisted for audit.
     * @param  string|null  $paymentPublicId  The payment our own `meta` came back with,
     *                                        for gateways that echo meta rather than the reference.
     * @param  string|null  $amount  What the gateway says was paid; null when it doesn't say.
     * @param  string|null  $transactionCode  The gateway's own receipt, e.g. the M-Pesa code.
     */
    public function __construct(
        public string $gatewayRef,
        public bool $successful,
        public array $raw = [],
        public ?string $paymentPublicId = null,
        public ?string $amount = null,
        public ?string $transactionCode = null,
    ) {}
}
