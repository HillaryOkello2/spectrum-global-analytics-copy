<?php

namespace App\Services\Billing;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Notifications\PaymentReceived;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class SubscriptionActivator
{
    public function __construct(
        private readonly InvoiceNumberGenerator $invoiceNumbers,
    ) {}

    /**
     * Complete a successful subscription payment in one transaction:
     * record payment → generate invoice → activate user → apply the change to the
     * subscription term (FR-15/16, §17.1).
     *
     * The kind of change is inferred from the payment:
     *  - payable is a SubscriptionTier  → upgrade: switch tier, fresh monthly term.
     *  - subscription never started yet  → signup:  activate, fresh monthly term.
     *  - subscription already started    → renewal: extend the same tier by a month.
     */
    public function activate(Payment $payment, array $rawCallback = [], ?string $transactionCode = null): void
    {
        // Read before the subscription is touched: once a signup has been
        // activated it is indistinguishable from a renewal.
        $kind = $this->kindOf($payment);

        DB::transaction(function () use ($payment, $rawCallback, $transactionCode): void {
            $payment->update([
                'status' => PaymentStatus::Successful,
                'failure_reason' => null,
                'transaction_code' => $transactionCode ?? $payment->transaction_code,
                'raw_callback' => $rawCallback,
                'paid_at' => now(),
            ]);

            $this->issueInvoice($payment);

            $payment->user->update(['status' => UserStatus::Active]);

            if ($payment->subscription !== null) {
                $this->applyToSubscription($payment, $payment->subscription);
            }
        });

        Audit::of($payment)->by($payment->user)
            ->change('status', PaymentStatus::Pending, $payment->status)
            ->with([
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'transactionCode' => $payment->transaction_code,
                'gatewayRef' => $payment->gateway_ref,
                'subscription' => $payment->subscription?->public_id,
            ])
            ->log('payment confirmed; subscription activated');

        // Queued: it renders a PDF and sends mail, neither of which belongs in
        // the gateway's callback request.
        $payment->user->notify(new PaymentReceived($payment->refresh(), $kind));
    }

    /**
     * Which of the three occasions this payment is, in the subscriber's terms.
     */
    private function kindOf(Payment $payment): string
    {
        if ($payment->payable instanceof SubscriptionTier) {
            // Both arrive as a payment against a tier; what separates them is
            // whether the subscriber had scheduled this one in advance.
            return $payment->subscription?->pending_tier_id === $payment->payable->id
                ? PaymentReceived::DOWNGRADE
                : PaymentReceived::UPGRADE;
        }

        return $payment->subscription?->starts_at === null
            ? PaymentReceived::SIGNUP
            : PaymentReceived::RENEWAL;
    }

    private function applyToSubscription(Payment $payment, Subscription $subscription): void
    {
        // Tier change: the payment was raised against the target tier, either
        // as an upgrade or as a scheduled downgrade being renewed into.
        if ($payment->payable instanceof SubscriptionTier) {
            $this->applyTierChange($subscription, $payment->payable);

            return;
        }

        // Signup: the subscription has never been activated.
        if ($subscription->starts_at === null) {
            $subscription->update([
                'status' => SubscriptionStatus::Active,
                // A fresh term starts its expiry notices over.
                'last_reminder_days' => null,
                'starts_at' => now(),
                'ends_at' => now()->addMonth(),
            ]);

            return;
        }

        // Renewal: extend by a month from whichever is later — now, or the
        // current end date — so renewing early never loses remaining days.
        $base = $subscription->ends_at?->isFuture() ? $subscription->ends_at : now();

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'last_reminder_days' => null,
            'ends_at' => $base->copy()->addMonth(),
        ]);
    }

    /**
     * Move the subscription onto a tier that has just been paid for.
     *
     * An upgrade starts a fresh month — it was charged the new tier's full
     * price, so the term it buys begins now. A downgrade the subscriber
     * scheduled is different: it was waiting for this term to end, so it
     * extends from the existing end date and never costs them days they had
     * already paid for.
     *
     * Either way `pending_tier_id` is cleared: whatever was scheduled has
     * either just happened or has been overtaken by an upgrade.
     */
    private function applyTierChange(Subscription $subscription, SubscriptionTier $tier): void
    {
        $scheduled = $subscription->pending_tier_id === $tier->id;

        $base = $scheduled && $subscription->ends_at?->isFuture()
            ? $subscription->ends_at
            : now();

        $subscription->update([
            'tier_id' => $tier->id,
            'pending_tier_id' => null,
            'status' => SubscriptionStatus::Active,
            // A fresh term starts its expiry notices over.
            'last_reminder_days' => null,
            'starts_at' => $scheduled ? ($subscription->starts_at ?? now()) : now(),
            'ends_at' => $base->copy()->addMonth(),
        ]);
    }

    private function issueInvoice(Payment $payment): void
    {
        $tierName = $payment->payable instanceof SubscriptionTier
            ? $payment->payable->name
            : $payment->subscription?->tier?->name;

        $payment->invoice()->create([
            'subscription_id' => $payment->subscription_id,
            'number' => $this->invoiceNumbers->next(),
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'issue_date' => today(),
            'line_items' => [
                [
                    'description' => trim($tierName.' subscription (monthly)').$this->conversionNote($payment),
                    'amount' => (string) $payment->amount,
                ],
            ],
        ]);
    }

    /**
     * A converted charge names the list price it came from, so the invoice
     * shows both what was paid and what the tier costs.
     */
    private function conversionNote(Payment $payment): string
    {
        if ($payment->exchange_rate === null) {
            return '';
        }

        return sprintf(
            ', %s %s at %s %s per %s',
            $payment->list_currency,
            $payment->list_amount,
            $payment->currency,
            rtrim(rtrim((string) $payment->exchange_rate, '0'), '.'),
            $payment->list_currency,
        );
    }
}
