<?php

namespace App\Providers;

use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\FakeGatewayDriver;
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
                // 'pgw' => new PgwGatewayDriver(config('payments.pgw')), — pending PGW API docs
                default => throw new InvalidArgumentException("Unsupported payment gateway [{$driver}]."),
            };
        });
    }
}
