<?php

namespace App\Services\Payments\DTOs;

readonly class CallbackResult
{
    /**
     * @param  array<string, mixed>  $raw  Full callback payload, persisted for audit.
     */
    public function __construct(
        public string $gatewayRef,
        public bool $successful,
        public array $raw = [],
    ) {}
}
