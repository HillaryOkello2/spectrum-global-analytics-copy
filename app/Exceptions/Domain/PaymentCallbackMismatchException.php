<?php

namespace App\Exceptions\Domain;

class PaymentCallbackMismatchException extends DomainException
{
    public function __construct(string $message = 'Payment callback could not be matched to a pending payment.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'payment_callback_mismatch';
    }
}
