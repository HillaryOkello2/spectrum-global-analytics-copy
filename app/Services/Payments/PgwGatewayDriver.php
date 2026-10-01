<?php

namespace App\Services\Payments;

use App\Enums\PaymentMethod;
use App\Exceptions\Domain\PaymentCallbackMismatchException;
use App\Exceptions\Domain\PaymentCallbackUnauthorizedException;
use App\Models\Payment;
use App\Services\Access\FrontendLinks;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\DTOs\CallbackResult;
use App\Services\Payments\DTOs\PaymentInitiation;
use App\Services\Payments\Exceptions\PaymentInitiationFailedException;
use App\Support\KenyanMsisdn;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * TechBiz's PGW. M-Pesa goes out as an STK push (Token, then MStk); card goes
 * through PGW's hosted checkout page. Either way the payment settles on PGW's
 * server-to-server callback, never on the browser coming back.
 *
 * Credentials are Spectrum's own merchant account, read from config. Never
 * borrow another PGW merchant's keys: the money settles into their account.
 */
class PgwGatewayDriver implements PaymentGateway
{
    /**
     * @param  array{base_url: string, merchant_key: string, merchant_secret: string, account_id: string,
     *               callback_key: string, callback_secret: string, callback_url: ?string,
     *               callback_ips: list<string>, order_prefix: string, timeout: int}  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly FrontendLinks $links,
    ) {}

    public function newReference(): string
    {
        return $this->config['order_prefix'].Str::upper(Str::random(12));
    }

    public function initiate(Payment $payment): PaymentInitiation
    {
        // PGW settles in whole shillings. ChargeCalculator converts the USD
        // list price; an unconverted amount must never be sent as shillings.
        if ($payment->currency !== 'KES') {
            throw new PaymentInitiationFailedException(
                "PGW charges KES, not {$payment->currency}. Set PAYMENT_CHARGE_CURRENCY=KES.",
            );
        }

        return match ($payment->method) {
            PaymentMethod::Mpesa => $this->stkPush($payment),
            PaymentMethod::Card => $this->checkout($payment),
        };
    }

    public function parseCallback(Request $request): CallbackResult
    {
        $this->authenticate($request);

        $reference = $request->input('BillReference');

        if (! is_string($reference) || $reference === '') {
            throw new PaymentCallbackMismatchException('Missing BillReference in callback payload.');
        }

        $successful = strtolower((string) $request->input('status')) === 'success';
        $amount = $this->amountPaid($request->input('AmountPaid'));

        if ($successful && $amount === null) {
            Log::warning('PGW reported a success without AmountPaid; the amount cannot be checked.', [
                'gateway_ref' => $reference,
            ]);
        }

        return new CallbackResult(
            gatewayRef: $reference,
            successful: $successful,
            // The body repeats the callback credentials; they are not audit data.
            raw: $request->except(['key', 'secret']),
            amount: $amount,
            transactionCode: $this->stringOrNull($request->input('TransactionCode')),
        );
    }

    private function stkPush(Payment $payment): PaymentInitiation
    {
        $msisdn = KenyanMsisdn::normalise($payment->phone ?? $payment->user->phone);

        if ($msisdn === null) {
            throw new PaymentInitiationFailedException('No Kenyan mobile number to send the M-Pesa prompt to.');
        }

        $this->ensureAccepted($this->http()->withToken($this->token())->post('apis/merchant/MStk/', [
            'accId' => $this->config['account_id'],
            'mobile' => $msisdn,
            'orderRef' => $payment->gateway_ref,
            'orderAmount' => $this->shillings($payment),
            'callbackUrl' => $this->callbackUrl(),
            'meta' => $this->meta($payment),
        ]), 'M-Pesa prompt');

        return new PaymentInitiation([
            'type' => PaymentMethod::Mpesa->value,
            'message' => sprintf(
                'Approve the M-Pesa prompt sent to %s to pay KES %s.',
                $msisdn,
                number_format($this->shillings($payment)),
            ),
        ]);
    }

    private function checkout(Payment $payment): PaymentInitiation
    {
        $response = $this->http()->withToken($this->merchantCredential())->post('apis/merchant/Checkout/', [
            'accId' => $this->config['account_id'],
            'email' => $payment->user->email,
            'mobile' => KenyanMsisdn::normalise($payment->user->phone) ?? $payment->user->phone,
            'orderRef' => $payment->gateway_ref,
            'currency' => 'KES',
            'orderAmount' => $this->shillings($payment),
            'callbackUrl' => $this->callbackUrl(),
            'redirectUrl' => $this->links->paymentReturn($payment),
            'meta' => $this->meta($payment),
        ]);

        $this->ensureAccepted($response, 'card checkout');

        return new PaymentInitiation([
            'type' => PaymentMethod::Card->value,
            'checkoutUrl' => rtrim($this->config['base_url'], '/').'/gateway/index.html?'
                .http_build_query(['token' => $this->requireToken($response, 'card checkout')]),
            'message' => 'Complete the card payment on the secure checkout page.',
        ]);
    }

    /**
     * A token per push. PGW publishes no lifetime for it, and pushes are too
     * rare to be worth caching one.
     */
    private function token(): string
    {
        $response = $this->http()->withToken($this->merchantCredential())->post('apis/merchant/Token/');

        $this->ensureAccepted($response, 'token request');

        return $this->requireToken($response, 'token request');
    }

    private function merchantCredential(): string
    {
        return base64_encode($this->config['merchant_key'].':'.$this->config['merchant_secret']);
    }

    /**
     * No automatic retry: a retried MStk is a second prompt on the payer's phone.
     */
    private function http(): PendingRequest
    {
        return Http::baseUrl($this->config['base_url'])
            ->acceptJson()
            ->asJson()
            ->timeout($this->config['timeout']);
    }

    /**
     * PGW's error responses carry `status: "failed"` and a `message`.
     */
    private function ensureAccepted(Response $response, string $what): void
    {
        $status = strtolower((string) $response->json('status'));

        if ($response->failed() || in_array($status, ['failed', 'fail', 'error'], true)) {
            throw new PaymentInitiationFailedException(sprintf(
                'PGW refused the %s (HTTP %d): %s',
                $what,
                $response->status(),
                Str::limit((string) ($response->json('message') ?? $response->body()), 200),
            ));
        }
    }

    private function requireToken(Response $response, string $what): string
    {
        $token = $response->json('token');

        if (! is_string($token) || $token === '') {
            throw new PaymentInitiationFailedException("PGW returned no token for the {$what}.");
        }

        return $token;
    }

    /**
     * PGW sends `Authorization: Bearer base64(callbackKey:callbackSecret)`.
     * Both halves must match, each compared in constant time: a check that
     * rejects only when both are wrong lets either half through on its own.
     */
    private function authenticate(Request $request): void
    {
        $allowedIps = $this->config['callback_ips'];

        if ($allowedIps !== [] && ! in_array($request->ip(), $allowedIps, true)) {
            throw new PaymentCallbackUnauthorizedException('Callback source is not allowed.');
        }

        $decoded = base64_decode((string) $request->bearerToken(), true);
        [$key, $secret] = array_pad(explode(':', (string) $decoded, 2), 2, '');

        $keyMatches = hash_equals((string) $this->config['callback_key'], $key);
        $secretMatches = hash_equals((string) $this->config['callback_secret'], $secret);

        if ($key === '' || $secret === '' || ! $keyMatches || ! $secretMatches) {
            throw new PaymentCallbackUnauthorizedException;
        }
    }

    /**
     * PGW has sent AmountPaid as an int, a numeric string, a float and null.
     */
    private function amountPaid(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = str_replace(',', '', trim($value));
        }

        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }

    private function shillings(Payment $payment): int
    {
        return (int) round((float) $payment->amount);
    }

    private function callbackUrl(): string
    {
        return $this->config['callback_url'] ?: route('api.webhooks.payments', 'pgw');
    }

    /**
     * PGW passes `meta` through as a JSON string.
     */
    private function meta(Payment $payment): string
    {
        return (string) json_encode(['payment' => $payment->public_id]);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
