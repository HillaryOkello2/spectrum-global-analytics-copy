<?php

namespace App\Exceptions\Domain;

class SubscriptionNotActiveException extends DomainException
{
    public function __construct(string $message = 'An active subscription is required.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'subscription_not_active';
    }
}
