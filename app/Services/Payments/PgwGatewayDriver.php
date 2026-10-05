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

        // Card always goes to the hosted page. M-Pesa goes there too by
        // default: that page collects the number and sends the STK push, which
        // is the flow this merchant account is set up for. Set
        // PGW_MPESA_MODE=stk to push from here instead.
        if ($payment->method === PaymentMethod::Mpesa && $this->config['mpesa_mode'] === 'stk') {
            return $this->stkPush($payment);
        }

        return $this->checkout($payment, $payment->method);
    }

    public function parseCallback(Request $request): CallbackResult
    {
        $this->authenticate($request);

        $reference = $request->input('BillReference');

        // PGW's own sample receiver matches on `meta` rather than
        // BillReference, so a callback may well arrive without one. We put the
        // payment's publicId in meta on the way out for exactly this case.
        $paymentPublicId = $this->paymentFromMeta($request->input('meta'));

        if ((! is_string($reference) || $reference === '') && $paymentPublicId === null) {
            throw new PaymentCallbackMismatchException('Callback carried neither a BillReference nor our meta.');
        }

        $reference = is_string($reference) ? $reference : '';

        $successful = strtolower((string) $request->input('status')) === 'success';
        $amount = $this->amountPaid($request->input('AmountPaid'));

        if ($successful && $amount === null) {
            Log::warning('PGW reported a success without AmountPaid; the amount cannot be checked.', [
                'gateway_ref' => $reference,
            ]);
        }

        return new CallbackResult(
            gatewayRef: $reference,
            paymentPublicId: $paymentPublicId,
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

    /**
     * PGW's hosted payment page. It returns a session token; the payer is sent
     * to gateway/index.html with it, picks M-Pesa or card there, and the page
     * takes it from there — including the STK push.
     */
    private function checkout(Payment $payment, PaymentMethod $method): PaymentInitiation
    {
        $mobile = $payment->phone ?? $payment->user->phone;

        $response = $this->http()->withToken($this->merchantCredential())->post('apis/merchant/Checkout/', [
            'accId' => $this->config['account_id'],
            'email' => $payment->user->email,
            'mobile' => KenyanMsisdn::normalise($mobile) ?? $mobile,
            'orderRef' => $payment->gateway_ref,
            'currency' => 'KES',
            'orderAmount' => $this->shillings($payment),
            'callbackUrl' => $this->callbackUrl(),
            'redirectUrl' => $this->links->paymentReturn($payment),
            'meta' => $this->meta($payment),
        ]);

        $this->ensureAccepted($response, 'checkout');

        return new PaymentInitiation([
            'type' => $method->value,
            'checkoutUrl' => rtrim($this->config['base_url'], '/').'/gateway/index.html?'
                .http_build_query(['token' => $this->requireToken($response, 'checkout')]),
            'message' => $method === PaymentMethod::Mpesa
                ? 'Open the payment page and enter your M-Pesa number to receive the prompt.'
                : 'Complete the card payment on the secure checkout page.',
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

        // The token we put in the callback URL, when one is configured. PGW's
        // sample receiver verifies nothing at all, so for a merchant issued no
        // callback credential this is the check that works.
        $token = $this->config['callback_token'];

        if (filled($token) && hash_equals((string) $token, (string) $request->query('t', ''))) {
            return;
        }

        $decoded = base64_decode((string) $request->bearerToken(), true);
        [$key, $secret] = array_pad(explode(':', (string) $decoded, 2), 2, '');

        $keyMatches = hash_equals((string) $this->config['callback_key'], $key);
        $secretMatches = hash_equals((string) $this->config['callback_secret'], $secret);

        if ($key === '' || $secret === '' || ! $keyMatches || ! $secretMatches) {
            // Enough to tell a wrong credential from an unexpected scheme on
            // the first live callback, without writing either half to a log.
            Log::warning('Rejected a PGW callback.', [
                'ip' => $request->ip(),
                'authorization_scheme' => Str::before((string) $request->header('Authorization'), ' ') ?: 'none',
                'credential_decoded' => $decoded !== false,
                'key_matches' => $keyMatches,
                'secret_matches' => $secretMatches,
                'body_carried_credentials' => $request->has('key') && $request->has('secret'),
                'url_token_expected' => filled($this->config['callback_token']),
                'url_token_present' => $request->query('t') !== null,
            ]);

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

    /**
     * `meta` goes out as the JSON string PGW passes straight back.
     *
     * @return string|null the payment publicId it carried, if any
     */
    private function paymentFromMeta(mixed $meta): ?string
    {
        $decoded = is_string($meta) ? json_decode($meta, true) : $meta;

        return is_array($decoded) && is_string($decoded['payment'] ?? null)
            ? $decoded['payment']
            : null;
    }

    private function shillings(Payment $payment): int
    {
        return (int) round((float) $payment->amount);
    }

    private function callbackUrl(): string
    {
        $url = $this->config['callback_url'] ?: route('api.webhooks.payments', 'pgw');
        $token = $this->config['callback_token'];

        return filled($token)
            ? $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['t' => $token])
            : $url;
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
