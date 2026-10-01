<?php

namespace App\Services\Billing;

use App\Models\SubscriptionTier;
use App\Services\Billing\DTOs\Charge;
use RuntimeException;

/**
 * Turns a tier's list price into what the gateway is asked to charge.
 *
 * Tiers are priced in USD; PGW settles in KES, and M-Pesa takes whole
 * shillings only. With `payments.charge_currency` set, the list price is
 * converted at the configured rate and rounded UP to a whole unit, so nobody
 * is ever charged less than the list price. Unset, the list price is charged
 * as it stands.
 */
class ChargeCalculator
{
    public function forTier(SubscriptionTier $tier): Charge
    {
        $listAmount = number_format((float) $tier->price, 2, '.', '');
        $listCurrency = strtoupper((string) $tier->currency);
        $currency = strtoupper((string) (config('payments.charge_currency') ?: $listCurrency));

        if ($currency === $listCurrency) {
            return new Charge($listAmount, $listCurrency, $listAmount, $listCurrency);
        }

        $rate = $this->rate($listCurrency, $currency);

        return new Charge(
            amount: number_format($this->convert($listAmount, $rate), 2, '.', ''),
            currency: $currency,
            listAmount: $listAmount,
            listCurrency: $listCurrency,
            exchangeRate: $rate,
        );
    }

    /**
     * Integer arithmetic throughout: cents times the rate in ten-thousandths,
     * rounded up to a whole unit. In floats, 50 × 129.30 lands a hair above
     * 6465 and ceil() would bill a shilling too many.
     */
    private function convert(string $listAmount, string $rate): int
    {
        $cents = (int) round((float) $listAmount * 100);
        $rateScaled = (int) round((float) $rate * 10_000);
        $scale = 100 * 10_000;

        return intdiv($cents * $rateScaled + $scale - 1, $scale);
    }

    /**
     * A missing rate is a configuration error, not a reason to charge the USD
     * figure as shillings.
     */
    private function rate(string $from, string $to): string
    {
        $rate = config("payments.exchange_rates.{$from}_{$to}");

        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw new RuntimeException("No {$from} to {$to} exchange rate is configured. Set PAYMENT_{$from}_{$to}_RATE.");
        }

        return number_format((float) $rate, 4, '.', '');
    }
}
