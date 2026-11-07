<?php

namespace App\Exceptions\Domain;

class DefaultFreeTierPriceLockedException extends DomainException
{
    public function __construct(string $message = 'The default free tier must stay at price 0.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'default_free_tier_price_locked';
    }
}
