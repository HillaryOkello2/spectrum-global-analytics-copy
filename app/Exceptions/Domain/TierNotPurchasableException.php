<?php

namespace App\Exceptions\Domain;

class TierNotPurchasableException extends DomainException
{
    public function __construct(string $message = 'The selected subscription tier is not available.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'tier_not_purchasable';
    }
}
