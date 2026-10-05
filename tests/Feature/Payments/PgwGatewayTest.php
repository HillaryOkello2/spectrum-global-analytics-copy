<?php

use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Enums\UserStatus;
use App\Models\Payment;
use App\Models\SubscriptionTier;
use App\Services\Payments\Contracts\PaymentGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config([
        'payments.gateway' => 'pgw',
        'payments.charge_currency' => 'KES',
        'payments.exchange_rates.USD_KES' => '129.00',
        'payments.pgw' => [
            'base_url' => 'https://pgw.test/pgw',
            'merchant_key' => 'merchant-key',
            'merchant_secret' => 'merchant-secret',
            'account_id' => 'ACC-1',
            'callback_key' => 'callback-key',
            'callback_secret' => 'callback-secret',
            'callback_url' => null,
            'callback_ips' => [],
            'callback_token' => null,
            'mpesa_mode' => 'checkout',
            'order_prefix' => 'SGA-',
            'timeout' => 30,
        ],
    ]);

    app()->forgetInstance(PaymentGateway::class);
});

function fakePgw(array $mstk = ['status' => 'success'], array $checkout = ['status' => 'success', 'token' => 'chk-123']): void
{
    Http::fake([
        'pgw.test/pgw/apis/merchant/Token/' => Http::response(['token' => 'tok-123']),
        'pgw.test/pgw/apis/merchant/MStk/' => Http::response($mstk),
        'pgw.test/pgw/apis/merchant/Checkout/' => Http::response($checkout),
    ]);
}

function pgwRegister(string $method): TestResponse
{
    $tier = SubscriptionTier::factory()->create(['name' => 'Premium', 'price' => 49.99, 'currency' => 'USD']);

    return test()->postJson(route('api.auth.register'), [
        'first_name' => 'Amina',
        'last_name' => 'Odhiambo',
        'phone' => '0712 345 678',
        'email' => 'amina@example.com',
        'country' => 'Kenya',
        'password' => 'Str0ngPassword!',
        'password_confirmation' => 'Str0ngPassword!',
        'tier' => $tier->public_id,
        'payment_method' => $method,
    ]);
}

/**
 * The shape PGW posts, credentials in the header as base64(key:secret).
 */
function pgwCallback(Payment $payment, array $overrides = [], ?string $auth = null): TestResponse
{
    return test()
        ->withHeader('Authorization', 'Bearer '.($auth ?? base64_encode('callback-key:callback-secret')))
        ->postJson(route('api.webhooks.payments', 'pgw'), [
            'key' => 'callback-key',
            'secret' => 'callback-secret',
            'status' => 'success',
            'BillReference' => $payment->gateway_ref,
            'AmountPaid' => '6449',
            'TransactionCode' => 'SGX1234ABC',
            'PaidDate' => now()->format('Y-m-d H:i:s'),
            'PaymentMethod' => 'MPESA Express',
            ...$overrides,
        ]);
}

// ---------------------------------------------------------------- initiation

it('pushes M-Pesa from here in stk mode, for whole shillings', function (): void {
    config(['payments.pgw.mpesa_mode' => 'stk']);
    app()->forgetInstance(PaymentGateway::class);

    fakePgw();

    pgwRegister('mpesa')
        ->assertCreated()
        ->assertJsonPath('data.payment.status', 'pending')
        ->assertJsonPath('data.payment.amount', '6449.00')
        ->assertJsonPath('data.payment.currency', 'KES')
        ->assertJsonPath('data.payment.listAmount', '49.99')
        ->assertJsonPath('data.payment.listCurrency', 'USD')
        ->assertJsonPath('data.instructions.type', 'mpesa');

    $payment = Payment::firstOrFail();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/Token/')
        && $request->hasHeader('Authorization', 'Bearer '.base64_encode('merchant-key:merchant-secret')));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/MStk/')
        && $request->hasHeader('Authorization', 'Bearer tok-123')
        && $request['accId'] === 'ACC-1'
        && $request['mobile'] === '254712345678'
        && $request['orderRef'] === $payment->gateway_ref
        && $request['orderAmount'] === 6449
        && $request['callbackUrl'] === route('api.webhooks.payments', 'pgw'));

    expect($payment->gateway_ref)->toStartWith('SGA-')
        ->and($payment->phone)->toBe('0712 345 678');
});

it('sends M-Pesa payers to the hosted page by default, which collects the number', function (): void {
    // PGW's hosted page asks for the M-Pesa number and sends the STK push
    // itself; that is the flow the merchant account is set up for.
    fakePgw();

    pgwRegister('mpesa')
        ->assertCreated()
        ->assertJsonPath('data.payment.status', 'pending')
        ->assertJsonPath('data.instructions.type', 'mpesa')
        ->assertJsonPath('data.instructions.checkoutUrl', 'https://pgw.test/pgw/gateway/index.html?token=chk-123');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/Checkout/')
        && $request['orderAmount'] === 6449
        && $request['mobile'] === '254712345678');

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/MStk/'));
});

it('puts our callback token in the URL it gives PGW, and takes it back as proof', function (): void {
    // PGW's own sample receiver authenticates nothing, so where no callback
    // credential is issued, this token is what makes a callback trustworthy.
    config(['payments.pgw.callback_token' => 'tok-secret-123']);
    app()->forgetInstance(PaymentGateway::class);

    fakePgw();
    pgwRegister('card');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/Checkout/')
        && str_contains((string) $request['callbackUrl'], 't=tok-secret-123'));

    $payment = Payment::firstOrFail();

    // No Authorization header at all: the token in the URL carries it.
    $this->postJson(route('api.webhooks.payments', 'pgw').'?t=tok-secret-123', [
        'status' => 'success',
        'BillReference' => $payment->gateway_ref,
        'AmountPaid' => '6449',
        'TransactionCode' => 'SGX1234ABC',
    ])->assertOk()->assertJsonPath('status', 'successful');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Successful);
});

it('rejects a callback carrying the wrong URL token', function (): void {
    config(['payments.pgw.callback_token' => 'tok-secret-123']);
    app()->forgetInstance(PaymentGateway::class);

    fakePgw();
    pgwRegister('card');
    $payment = Payment::firstOrFail();

    $this->postJson(route('api.webhooks.payments', 'pgw').'?t=wrong', [
        'status' => 'success',
        'BillReference' => $payment->gateway_ref,
    ])->assertUnauthorized();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('matches a callback on our meta when it carries no BillReference', function (): void {
    // PGW's sample receiver reads `meta`, not BillReference, so a callback
    // without one has to settle anyway.
    fakePgw();
    pgwRegister('card');
    $payment = Payment::firstOrFail();

    pgwCallback($payment, [
        'BillReference' => null,
        'meta' => json_encode(['payment' => $payment->public_id]),
    ])->assertOk()->assertJsonPath('status', 'successful');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Successful);
});

it('refuses a callback that identifies no payment at all', function (): void {
    fakePgw();
    pgwRegister('card');

    pgwCallback(Payment::firstOrFail(), ['BillReference' => null, 'meta' => 'test'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'payment_callback_mismatch');
});

it('sends card payers to the hosted checkout, returning them to the portal', function (): void {
    fakePgw();

    $response = pgwRegister('card')->assertCreated();
    $payment = Payment::firstOrFail();

    $response
        ->assertJsonPath('data.instructions.type', 'card')
        ->assertJsonPath('data.instructions.checkoutUrl', 'https://pgw.test/pgw/gateway/index.html?token=chk-123');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/Checkout/')
        && $request->hasHeader('Authorization', 'Bearer '.base64_encode('merchant-key:merchant-secret'))
        && $request['currency'] === 'KES'
        && $request['orderAmount'] === 6449
        && $request['orderRef'] === $payment->gateway_ref
        && $request['redirectUrl'] === config('frontend.subscriber_url').'/payment/return?payment='.$payment->public_id);

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/MStk/'));
});

it('fails the payment, not the signup, when PGW refuses it', function (): void {
    // The real shape of a bad merchant credential: {"status":"failed","message":"Invalid User"}.
    fakePgw(checkout: ['status' => 'failed', 'message' => 'Invalid User']);

    pgwRegister('mpesa')
        ->assertCreated()
        ->assertJsonPath('data.payment.status', 'failed')
        ->assertJsonPath('data.payment.failureReason', 'gateway_error');

    expect(Payment::firstOrFail()->user->status)->toBe(UserStatus::Pending);
});

it('fails the payment when PGW cannot be reached', function (): void {
    Http::fake(['*' => Http::failedConnection()]);

    pgwRegister('card')
        ->assertCreated()
        ->assertJsonPath('data.payment.status', 'failed')
        ->assertJsonPath('data.payment.failureReason', 'gateway_error');
});

it('will not build the PGW driver with a setting missing', function (): void {
    config(['payments.pgw.account_id' => null]);

    expect(fn () => app(PaymentGateway::class))->toThrow(InvalidArgumentException::class, 'account_id');
});

// ---------------------------------------------------------------- callback

it('activates the account on an authenticated PGW callback', function (): void {
    fakePgw();
    pgwRegister('mpesa');
    $payment = Payment::firstOrFail();

    pgwCallback($payment)->assertOk()->assertJsonPath('status', 'successful');

    $payment->refresh();

    expect($payment->status)->toBe(PaymentStatus::Successful)
        ->and($payment->transaction_code)->toBe('SGX1234ABC')
        ->and($payment->raw_callback)->not->toHaveKeys(['key', 'secret'])
        ->and($payment->user->status)->toBe(UserStatus::Active)
        ->and($payment->invoice->currency)->toBe('KES')
        ->and((float) $payment->invoice->amount)->toBe(6449.0)
        ->and($payment->invoice->line_items[0]['description'])
        ->toBe('Premium subscription (monthly), USD 49.99 at KES 129 per USD');
});

it('rejects a callback without both halves of the callback credentials', function (string $auth): void {
    fakePgw();
    pgwRegister('mpesa');
    $payment = Payment::firstOrFail();

    pgwCallback($payment, auth: $auth)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'payment_callback_unauthorized');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
})->with([
    'wrong key' => [base64_encode('nope:callback-secret')],
    'wrong secret' => [base64_encode('callback-key:nope')],
    'the key alone' => [base64_encode('callback-key')],
    'not base64' => ['%%%'],
    'empty' => [''],
]);

it('does not activate when PGW reports less than was charged', function (): void {
    fakePgw();
    pgwRegister('mpesa');
    $payment = Payment::firstOrFail();

    pgwCallback($payment, ['AmountPaid' => '49.99'])->assertOk()->assertJsonPath('status', 'failed');

    $payment->refresh();

    expect($payment->failure_reason)->toBe(PaymentFailureReason::AmountMismatch)
        ->and($payment->transaction_code)->toBe('SGX1234ABC')
        ->and($payment->invoice)->toBeNull()
        ->and($payment->user->status)->toBe(UserStatus::Pending);
});

it('reads AmountPaid however PGW types it', function (mixed $amount): void {
    fakePgw();
    pgwRegister('mpesa');

    pgwCallback(Payment::firstOrFail(), ['AmountPaid' => $amount])->assertJsonPath('status', 'successful');
})->with([
    'an int' => [6449],
    'a string' => ['6449'],
    'a float' => [6449.0],
    'with a comma' => ['6,449.00'],
    'missing' => [null],
]);

it('is idempotent for replayed PGW callbacks', function (): void {
    fakePgw();
    pgwRegister('mpesa');
    $payment = Payment::firstOrFail();

    pgwCallback($payment)->assertOk();
    pgwCallback($payment)->assertOk()->assertJsonPath('status', 'successful');

    expect($payment->invoice()->count())->toBe(1);
});

it('still activates a payment whose success arrives after it expired', function (): void {
    fakePgw();
    pgwRegister('mpesa');
    $payment = Payment::firstOrFail();

    $this->travel(31)->minutes();
    $this->artisan('payments:expire-pending')->assertSuccessful();
    expect($payment->refresh()->failure_reason)->toBe(PaymentFailureReason::Expired);

    pgwCallback($payment)->assertJsonPath('status', 'successful');

    expect($payment->refresh()->user->status)->toBe(UserStatus::Active)
        ->and($payment->failure_reason)->toBeNull();
});

it('ignores callbacks addressed to a gateway that is not configured', function (): void {
    fakePgw();
    pgwRegister('mpesa');
    $payment = Payment::firstOrFail();

    $this->postJson(route('api.webhooks.payments', 'fake'), [
        'gateway_ref' => $payment->gateway_ref,
        'result' => 'success',
    ])->assertNotFound();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});
