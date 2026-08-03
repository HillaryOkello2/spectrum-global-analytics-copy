<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum Frequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';

    /**
     * Whether a topic last generated at $lastGeneratedAt is due again at $now.
     */
    public function isDue(?CarbonInterface $lastGeneratedAt, CarbonInterface $now): bool
    {
        if ($lastGeneratedAt === null) {
            return true;
        }

        return match ($this) {
            self::Daily => $lastGeneratedAt->diffInDays($now) >= 1,
            self::Weekly => $lastGeneratedAt->diffInWeeks($now) >= 1,
            self::Monthly => $lastGeneratedAt->diffInMonths($now) >= 1,
            self::Quarterly => $lastGeneratedAt->diffInMonths($now) >= 3,
        };
    }
}
