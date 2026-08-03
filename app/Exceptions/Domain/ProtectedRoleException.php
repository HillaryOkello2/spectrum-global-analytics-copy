<?php

namespace App\Exceptions\Domain;

class ProtectedRoleException extends DomainException
{
    public function __construct(string $message = 'Built-in roles cannot be renamed, edited or deleted. Create a new role instead.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'protected_role';
    }
}
