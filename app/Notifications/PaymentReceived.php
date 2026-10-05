<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Services\Access\FrontendLinks;
use App\Services\Billing\InvoicePdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Sent once a payment settles, with the invoice attached as a PDF.
 *
 * One notification covers all three occasions — a first subscription, a
 * renewal and an upgrade — because the subscriber wants the same facts each
 * time: what was taken, what it bought, and until when.
 *
 * Queued: it renders a PDF and talks to an SMTP server, neither of which
 * belongs in the gateway's callback request.
 */
class PaymentReceived extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const SIGNUP = 'signup';

    public const RENEWAL = 'renewal';

    public const UPGRADE = 'upgrade';

    public function __construct(
        private readonly Payment $payment,
        private readonly string $kind = self::SIGNUP,
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
        $payment = $this->payment->loadMissing(['invoice', 'subscription.tier']);
        $tier = $payment->subscription?->tier?->name ?? 'your';
        $amount = $payment->currency.' '.number_format((float) $payment->amount, 2);

        $mail = (new MailMessage)
            ->subject(match ($this->kind) {
                self::UPGRADE => 'Your subscription has been upgraded',
                self::RENEWAL => 'Your subscription has been renewed',
                default => 'Your subscription is active',
            })
            ->greeting("Hello {$notifiable->first_name},")
            ->line(match ($this->kind) {
                self::UPGRADE => "We have received {$amount}. You are now on the {$tier} tier.",
                self::RENEWAL => "We have received {$amount}. Your {$tier} subscription has been renewed.",
                default => "We have received {$amount}. Your {$tier} subscription is now active.",
            });

        if ($payment->exchange_rate !== null) {
            $mail->line(
                "Charged as {$amount} against a listed price of "
                ."{$payment->list_currency} {$payment->list_amount}."
            );
        }

        if ($payment->transaction_code !== null) {
            $mail->line("Payment reference: {$payment->transaction_code}");
        }

        if ($payment->subscription?->ends_at !== null) {
            $mail->line('Your access runs until '.$payment->subscription->ends_at->format('j F Y').'.');
        }

        if ($payment->invoice !== null) {
            $pdf = app(InvoicePdf::class);

            $mail->line("Invoice {$payment->invoice->number} is attached.")
                ->attachData(
                    $pdf->render($payment->invoice),
                    $pdf->filename($payment->invoice),
                    ['mime' => 'application/pdf'],
                );
        }

        return $mail
            ->action('Sign in', app(FrontendLinks::class)->login($notifiable))
            ->line('Thank you for subscribing.');
    }
}
