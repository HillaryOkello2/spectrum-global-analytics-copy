<?php

namespace App\Support;

/**
 * Kenyan mobile numbers in the MSISDN form M-Pesa expects: 254 followed by the
 * nine-digit subscriber number, which starts 7 or 1.
 */
final class KenyanMsisdn
{
    /**
     * `0712 345 678`, `+254712345678`, `254712345678` and `712345678` all give
     * `254712345678`. Anything that is not a Kenyan mobile gives null.
     */
    public static function normalise(?string $phone): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $phone);

        $local = match (true) {
            strlen($digits) === 12 && str_starts_with($digits, '254') => substr($digits, 3),
            strlen($digits) === 10 && str_starts_with($digits, '0') => substr($digits, 1),
            strlen($digits) === 9 => $digits,
            default => null,
        };

        return $local !== null && preg_match('/^[17]\d{8}$/', $local) === 1 ? '254'.$local : null;
    }
}
