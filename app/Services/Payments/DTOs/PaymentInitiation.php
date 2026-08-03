<?php

namespace App\Services\Payments\DTOs;

readonly class PaymentInitiation
{
    /**
     * @param  string  $gatewayRef  Gateway-side reference for the transaction.
     * @param  array<string, mixed>  $instructions  Frontend instructions: checkout URL,
     *                                              STK push status, etc.
     */
    public function __construct(
        public string $gatewayRef,
        public array $instructions = [],
    ) {}
}
