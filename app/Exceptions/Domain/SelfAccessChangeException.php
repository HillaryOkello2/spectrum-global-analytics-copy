<?php

namespace App\Exceptions\Domain;

class SelfAccessChangeException extends DomainException
{
    public function __construct(string $message = 'You cannot change your own roles or permissions. Ask another administrator.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'self_access_change';
    }
}
