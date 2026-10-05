<?php

namespace App\Console\Commands;

use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Notifications\PaymentFailed;
use Illuminate\Console\Command;

/**
 * PGW calls back on success only. A declined or ignored M-Pesa prompt is never
 * reported, so without this its payment would stay pending for good and could
 * never be retried. A success that turns up after expiry still activates: see
 * PaymentCallbackHandler.
 */
class ExpirePendingPaymentsCommand extends Command
{
    protected $signature = 'payments:expire-pending';

    protected $description = 'Fail pending payments nobody completed within the payment timeout';

    public function handle(): int
    {
        $expired = 0;

        // Row by row rather than one mass update: each payer is told their
        // attempt lapsed, and given the link that starts a new one.
        Payment::stale()->with('user')->chunkById(100, function ($payments) use (&$expired): void {
            foreach ($payments as $payment) {
                $payment->update([
                    'status' => PaymentStatus::Failed,
                    'failure_reason' => PaymentFailureReason::Expired,
                ]);

                $payment->user?->notify(new PaymentFailed($payment));
                $expired++;
            }
        });

        $this->info("Expired {$expired} payment(s).");

        return self::SUCCESS;
    }
}
