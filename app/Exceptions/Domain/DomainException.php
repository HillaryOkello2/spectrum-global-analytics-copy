<?php

namespace App\Exceptions\Domain;

use Exception;

abstract class DomainException extends Exception
{
    /**
     * HTTP status the exception handler renders this exception with.
     */
    public function status(): int
    {
        return 422;
    }

    /**
     * Machine-readable error code for API clients.
     */
    abstract public function errorCode(): string;

    /**
     * Extra payload merged into the error response.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [];
    }
}
