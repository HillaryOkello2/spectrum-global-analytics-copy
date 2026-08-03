<?php

namespace App\Exceptions\Domain;

class RoleEscalationException extends DomainException
{
    public function __construct(string $message = 'Only a System Admin can grant or revoke the System Admin role.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'role_escalation';
    }
}
