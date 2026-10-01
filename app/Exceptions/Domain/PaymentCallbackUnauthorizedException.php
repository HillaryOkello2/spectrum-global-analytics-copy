<?php

namespace App\Exceptions\Domain;

class PaymentCallbackUnauthorizedException extends DomainException
{
    public function __construct(string $message = 'Payment callback credentials are missing or invalid.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 401;
    }

    public function errorCode(): string
    {
        return 'payment_callback_unauthorized';
    }
}
