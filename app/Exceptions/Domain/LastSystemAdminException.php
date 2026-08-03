<?php

namespace App\Exceptions\Domain;

class LastSystemAdminException extends DomainException
{
    public function __construct(string $message = 'This is the last System Admin — promote another user before removing this role.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'last_system_admin';
    }
}
