<?php

namespace App\Exceptions\Domain;

class InvalidTierChangeException extends DomainException
{
    public function __construct(string $message = 'The requested subscription tier change is not allowed.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'invalid_tier_change';
    }
}
