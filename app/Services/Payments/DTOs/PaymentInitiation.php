<?php

namespace App\Services\Payments\DTOs;

readonly class PaymentInitiation
{
    /**
     * @param  array<string, mixed>  $instructions  What the frontend shows next: the
     *                                              M-Pesa prompt message, or a card
     *                                              `checkoutUrl`.
     */
    public function __construct(
        public array $instructions = [],
    ) {}
}
