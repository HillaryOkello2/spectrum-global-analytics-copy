<?php

namespace App\Services\Billing\DTOs;

/**
 * What a payment asks the gateway for, next to the tier price it came from.
 */
readonly class Charge
{
    public function __construct(
        public string $amount,
        public string $currency,
        public string $listAmount,
        public string $listCurrency,
        public ?string $exchangeRate = null,
    ) {}
}
