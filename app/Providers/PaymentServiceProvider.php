<?php

namespace App\Providers;

use App\Services\Access\FrontendLinks;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\FakeGatewayDriver;
use App\Services\Payments\PgwGatewayDriver;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, function ($app) {
            $driver = config('payments.gateway');

            return match ($driver) {
                'fake' => new FakeGatewayDriver,
                'pgw' => new PgwGatewayDriver($this->pgwConfig(), $app->make(FrontendLinks::class)),
                default => throw new InvalidArgumentException("Unsupported payment gateway [{$driver}]."),
            };
        });
    }

    /**
     * Refuse to build the driver when a setting is missing, rather than
     * discover it halfway through someone's payment.
     *
     * @return array<string, mixed>
     */
    private function pgwConfig(): array
    {
        $config = config('payments.pgw');

        // The callback pair is absent from this list on purpose: it falls back
        // to the merchant credential in config/payments.php.
        $required = ['base_url', 'merchant_key', 'merchant_secret', 'account_id'];
        $missing = array_keys(array_filter(Arr::only($config, $required), fn ($value) => blank($value)));

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'PGW is missing configuration: '.implode(', ', $missing).'. See the PGW_* keys in .env.example.',
            );
        }

        if (strtoupper((string) config('payments.charge_currency')) !== 'KES') {
            throw new InvalidArgumentException(
                'PGW charges KES: set PAYMENT_CHARGE_CURRENCY=KES and PAYMENT_USD_KES_RATE.',
            );
        }

        return $config;
    }
}
