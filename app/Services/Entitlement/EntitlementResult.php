<?php

namespace App\Services\Entitlement;

use App\Enums\AccessType;
use App\Enums\EntitlementLevel;

readonly class EntitlementResult
{
    public function __construct(
        public EntitlementLevel $level,
        public ?AccessType $accessType = null,
        public ?int $used = null,
        public ?int $limit = null,
    ) {}

    public function grantsFullAccess(): bool
    {
        return $this->level === EntitlementLevel::FullAccess;
    }

    public function grantsRedactedAccess(): bool
    {
        return $this->level === EntitlementLevel::RedactedAccess;
    }

    /**
     * Same decision, downgraded to the redacted document. Used where the tier
     * withholds the full document but a redacted version has been approved.
     */
    public function redacted(): self
    {
        return new self(EntitlementLevel::RedactedAccess, $this->accessType, $this->used, $this->limit);
    }

    /**
     * Quota metadata for the API response `meta` block.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return array_filter([
            'access' => $this->accessType?->value,
            'used' => $this->used,
            'limit' => $this->limit,
        ], fn ($value) => $value !== null);
    }
}
