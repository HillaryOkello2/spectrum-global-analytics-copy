<?php

use App\Models\SubscriptionTier;
use App\Support\KenyanMsisdn;
use Illuminate\Testing\TestResponse;

it('normalises Kenyan mobiles to the MSISDN form M-Pesa expects', function (string $input, ?string $expected): void {
    expect(KenyanMsisdn::normalise($input))->toBe($expected);
})->with([
    ['0712345678', '254712345678'],
    ['+254 712 345 678', '254712345678'],
    ['254712345678', '254712345678'],
    ['712345678', '254712345678'],
    ['0110123456', '254110123456'],
    'a US number' => ['+1 202 555 0143', null],
    'a Nairobi landline' => ['0201234567', null],
    'nothing' => ['', null],
]);

function registerWithPhone(SubscriptionTier $tier, string $phone, ?string $method): TestResponse
{
    static $n = 0;
    $n++;

    return test()->postJson(route('api.auth.register'), array_filter([
        'first_name' => 'Dana',
        'last_name' => 'Kariuki',
        'phone' => $phone,
        'email' => "dana{$n}@example.com",
        'country' => 'Kenya',
        'password' => 'Str0ngPassword!',
        'password_confirmation' => 'Str0ngPassword!',
        'tier' => $tier->public_id,
        'payment_method' => $method,
    ]));
}

it('asks for a Kenyan number before a paid signup is sent an M-Pesa prompt', function (): void {
    $tier = SubscriptionTier::factory()->create(['price' => 49.99]);

    // M-Pesa is the default method, so leaving it out needs a Kenyan number too.
    registerWithPhone($tier, '+1 202 555 0143', 'mpesa')->assertJsonValidationErrors('phone');
    registerWithPhone($tier, '+1 202 555 0143', null)->assertJsonValidationErrors('phone');

    registerWithPhone($tier, '+1 202 555 0143', 'card')->assertCreated();
    registerWithPhone($tier, '0712 345 678', 'mpesa')->assertCreated();
});

it('asks no free signup for an M-Pesa number', function (): void {
    $tier = SubscriptionTier::factory()->free()->create();

    registerWithPhone($tier, '+1 202 555 0143', null)->assertCreated();
});
