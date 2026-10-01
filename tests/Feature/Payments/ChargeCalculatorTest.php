<?php

use App\Models\SubscriptionTier;
use App\Services\Billing\ChargeCalculator;

it('charges the list price unchanged when no charge currency is set', function (): void {
    config(['payments.charge_currency' => null]);
    $tier = SubscriptionTier::factory()->create(['price' => 49.99, 'currency' => 'USD']);

    $charge = app(ChargeCalculator::class)->forTier($tier);

    expect($charge->amount)->toBe('49.99')
        ->and($charge->currency)->toBe('USD')
        ->and($charge->exchangeRate)->toBeNull();
});

it('converts a USD list price to whole shillings, rounding up', function (float $price, string $rate, string $expected): void {
    config(['payments.charge_currency' => 'KES', 'payments.exchange_rates.USD_KES' => $rate]);
    $tier = SubscriptionTier::factory()->create(['price' => $price, 'currency' => 'USD']);

    $charge = app(ChargeCalculator::class)->forTier($tier);

    expect($charge->amount)->toBe($expected)
        ->and($charge->currency)->toBe('KES')
        ->and($charge->listAmount)->toBe(number_format($price, 2, '.', ''))
        ->and($charge->listCurrency)->toBe('USD');
})->with([
    'a fraction rounds up' => [49.99, '129.00', '6449.00'],
    // In floats 50 × 129.30 lands a hair above 6465, and ceil() makes it 6466.
    'an exact product stays exact' => [50.00, '129.30', '6465.00'],
    'a four-decimal rate' => [199.99, '128.7500', '25749.00'],
]);

it('refuses to charge when no exchange rate is configured', function (): void {
    config(['payments.charge_currency' => 'KES', 'payments.exchange_rates.USD_KES' => null]);
    $tier = SubscriptionTier::factory()->create(['price' => 49.99, 'currency' => 'USD']);

    expect(fn () => app(ChargeCalculator::class)->forTier($tier))
        ->toThrow(RuntimeException::class, 'PAYMENT_USD_KES_RATE');
});

it('shows the converted charge on the pricing page', function (): void {
    config(['payments.charge_currency' => 'KES', 'payments.exchange_rates.USD_KES' => '129.00']);
    SubscriptionTier::factory()->create(['price' => 49.99, 'currency' => 'USD']);

    $this->getJson(route('api.tiers.index'))
        ->assertOk()
        ->assertJsonPath('data.0.price', '49.99')
        ->assertJsonPath('data.0.charge', ['amount' => '6449.00', 'currency' => 'KES']);
});
