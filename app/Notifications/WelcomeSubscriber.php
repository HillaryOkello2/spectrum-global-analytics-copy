<?php

namespace App\Notifications;

use App\Enums\AccessType;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\Access\FrontendLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to every subscriber the moment their account exists — freemium and paid
 * alike (FR-10/11).
 *
 * Before this, a freemium signup heard nothing at all: the API handed the
 * browser a token and that was the whole of it, so there was no record in the
 * subscriber's inbox of the address they had signed up with. A paid signup
 * heard only from PaymentReceived, which may be minutes away and never arrives
 * if they abandon the payment.
 *
 * The two cases differ in one thing only — whether the account is already open
 * — so they share a notification rather than splitting into two that would
 * drift apart.
 *
 * Queued: signup is a page the user is waiting on, and SMTP does not belong in
 * it. The default queue worker runs each minute (routes/console.php).
 */
class WelcomeSubscriber extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly Subscription $subscription,
        private readonly ?Payment $payment = null,
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
        $subscription = $this->subscription->loadMissing(['tier.allocations.component']);
        $tier = $subscription->tier;

        $mail = (new MailMessage)
            ->subject($this->payment === null
                ? 'Welcome to '.config('app.name')
                : 'Your '.config('app.name').' account — one step left')
            ->greeting("Welcome, {$notifiable->first_name}.");

        return $this->payment === null
            ? $this->activeAccount($mail, $notifiable, $tier?->name, $subscription)
            : $this->awaitingPayment($mail, $tier?->name);
    }

    /**
     * Freemium, and any tier that needed no payment: the account is open, so
     * the mail says what it opens and sends them to it.
     */
    private function activeAccount(MailMessage $mail, object $notifiable, ?string $tierName, Subscription $subscription): MailMessage
    {
        $mail->line("Your account is active on the {$tierName} tier.");

        if ($subscription->ends_at !== null) {
            $mail->line('This term runs until '.$subscription->ends_at->format('j F Y')
                .'. We will write to you before it ends.');
        }

        $this->listAllocations($mail, $subscription);

        return $mail
            ->action('Open your library', app(FrontendLinks::class)->login($notifiable))
            ->line('You signed up with '.$notifiable->email.'. Keep this email — it is the address to sign in with.');
    }

    /**
     * A paid signup, still waiting on the gateway. Deliberately does not
     * promise access: the account is reserved, not open.
     */
    private function awaitingPayment(MailMessage $mail, ?string $tierName): MailMessage
    {
        $amount = $this->payment->currency.' '.number_format((float) $this->payment->amount, 2);

        return $mail
            ->line("We have reserved your account on the {$tierName} tier.")
            ->line("Your subscription opens as soon as the {$amount} payment clears. If you have already paid, "
                .'you can ignore this — the receipt follows separately.')
            ->action('Complete your payment', app(FrontendLinks::class)->paymentReturn($this->payment))
            ->line('The page above shows the payment as it settles, and will let you try again '
                .'with M-Pesa or a card if the first attempt did not go through.');
    }

    /**
     * What the tier actually unlocks, in the subscriber's words rather than
     * access-type codes. Omitted entirely when the tier opens nothing yet — an
     * empty heading reads worse than no heading.
     */
    private function listAllocations(MailMessage $mail, Subscription $subscription): void
    {
        $granted = $subscription->tier?->allocations
            ->filter(fn ($allocation) => $allocation->access_type !== AccessType::Denied)
            ->sortBy(fn ($allocation) => $allocation->component?->sort_order);

        if (blank($granted)) {
            return;
        }

        $mail->line('**Your tier includes:**');

        foreach ($granted as $allocation) {
            $mail->line('- '.$allocation->component?->name.($allocation->access_type === AccessType::Metered
                ? " — up to {$allocation->monthly_limit} a month"
                : ' — unlimited'));
        }
    }
}
