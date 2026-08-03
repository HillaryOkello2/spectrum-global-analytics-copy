<?php

namespace App\Exceptions\Domain;

use App\Enums\TaskStatus;

class InvalidTaskTransitionException extends DomainException
{
    public function __construct(TaskStatus $from, TaskStatus $to)
    {
        parent::__construct("Cannot transition task from [{$from->value}] to [{$to->value}].");
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'invalid_task_transition';
    }
}
