<?php

namespace App\Console\Commands;

use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Models\Payment;
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
        $expired = Payment::stale()->update([
            'status' => PaymentStatus::Failed,
            'failure_reason' => PaymentFailureReason::Expired,
        ]);

        $this->info("Expired {$expired} payment(s).");

        return self::SUCCESS;
    }
}
