<?php

namespace App\Notifications;

use App\Enums\PaymentFailureReason;
use App\Models\Payment;
use App\Services\Access\FrontendLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when a payment ends `failed`, with a link back to the page that can
 * retry it.
 *
 * Without this a subscriber who closed the payment page learns nothing: the
 * account simply never activates, and a signup cannot even sign in to find out
 * why.
 */
class PaymentFailed extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly Payment $payment,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payment = $this->payment;
        $amount = $payment->currency.' '.number_format((float) $payment->amount, 2);

        return (new MailMessage)
            ->subject('Your payment did not go through')
            ->greeting("Hello {$notifiable->first_name},")
            ->line(match ($payment->failure_reason) {
                PaymentFailureReason::Expired => "The payment of {$amount} was not completed in time, so we have cancelled it.",
                PaymentFailureReason::AmountMismatch => "The payment we received was less than the {$amount} due, so your subscription has not started.",
                default => "Your payment of {$amount} was declined.",
            })
            ->line('Nothing has been taken from you for this attempt. You can try again below, with M-Pesa or a card.')
            ->action('Complete your payment', app(FrontendLinks::class)->paymentReturn($payment))
            ->line('If you believe this is a mistake, reply to this email and we will look into it.');
    }
}
