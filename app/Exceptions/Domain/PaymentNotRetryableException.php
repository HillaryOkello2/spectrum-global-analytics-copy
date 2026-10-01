<?php

namespace App\Exceptions\Domain;

class PaymentNotRetryableException extends DomainException
{
    public function __construct(string $message = 'This payment cannot be retried.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'payment_not_retryable';
    }
}
