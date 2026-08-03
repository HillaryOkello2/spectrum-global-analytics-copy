<?php

namespace App\Exceptions\Domain;

class QuotaExhaustedException extends DomainException
{
    public function __construct(
        public readonly int $used,
        public readonly int $limit,
    ) {
        parent::__construct('Monthly product limit reached for this component on your subscription tier.');
    }

    public function status(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'quota_exhausted';
    }

    public function context(): array
    {
        return ['used' => $this->used, 'limit' => $this->limit];
    }
}
