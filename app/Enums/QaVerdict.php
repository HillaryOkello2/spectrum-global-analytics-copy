<?php

namespace App\Enums;

/**
 * The verdict line of a QA audit report (SGA-QCP §AUDIT OUTPUT TEMPLATE):
 *
 *   FINAL VERDICT: [GREEN LIGHT - APPROVED FOR CLIENT RELEASE
 *                 / RED LIGHT - REJECTED FOR RE-RUN]
 *
 * `Unknown` is its own case rather than a default to green or red: an auditor
 * that answered off-template told us nothing, and treating "nothing" as either
 * a pass or a failure is a decision that belongs in config, not in a parser.
 */
enum QaVerdict: string
{
    case Green = 'green';
    case Red = 'red';
    case Unknown = 'unknown';

    public function isPass(): bool
    {
        return $this === self::Green;
    }

    public function isFail(): bool
    {
        return $this === self::Red;
    }

    public function label(): string
    {
        return match ($this) {
            self::Green => 'GREEN LIGHT',
            self::Red => 'RED LIGHT',
            self::Unknown => 'NO VERDICT',
        };
    }
}
