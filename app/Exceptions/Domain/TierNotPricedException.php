<?php

namespace App\Exceptions\Domain;

class TierNotPricedException extends DomainException
{
    public function __construct(string $message = "This plan isn't available right now.")
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'tier_not_priced';
    }
}
