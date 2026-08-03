<?php

namespace App\Exceptions\Domain;

class RoleInUseException extends DomainException
{
    public function __construct(private readonly int $usersCount)
    {
        parent::__construct(
            "This role is still assigned to {$usersCount} user(s). Move them to another role first.",
        );
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'role_in_use';
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return ['usersCount' => $this->usersCount];
    }
}
