<?php

namespace App\Services\Billing;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionTier;
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

        activity()
            ->causedBy($payment->user)
            ->performedOn($payment)
            ->log('payment confirmed; subscription activated');
    }

    private function applyToSubscription(Payment $payment, Subscription $subscription): void
    {
        // Upgrade: the payment was raised against the target tier.
        if ($payment->payable instanceof SubscriptionTier) {
            $subscription->update([
                'tier_id' => $payment->payable->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now(),
                'ends_at' => now()->addMonth(),
            ]);

            return;
        }

        // Signup: the subscription has never been activated.
        if ($subscription->starts_at === null) {
            $subscription->update([
                'status' => SubscriptionStatus::Active,
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
