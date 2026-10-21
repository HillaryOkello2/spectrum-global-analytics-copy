<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Models\SubscriptionTier;
use App\Services\Access\FrontendLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Confirms a downgrade the subscriber has scheduled — and, just as
 * importantly, confirms that nothing has changed yet.
 *
 * A tier change that takes effect weeks later is exactly the kind a subscriber
 * forgets asking for and then reports as a fault, so the mail states the date
 * it lands and how to call it off.
 */
class SubscriptionDowngradeScheduled extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly Subscription $subscription,
        private readonly SubscriptionTier $target,
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
        $subscription = $this->subscription->loadMissing('tier');
        $ends = $subscription->ends_at?->format('j F Y');
        $current = $subscription->tier?->name;

        $mail = (new MailMessage)
            ->subject("Your move to {$this->target->name} is scheduled")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("You have asked to move from {$current} to {$this->target->name}.")
            ->line("Nothing changes today. Your {$current} access runs to {$ends} — the term you have "
                ."already paid for — and {$this->target->name} begins after that.");

        $mail->line($this->target->isFree()
            // A free target needs no payment, so the term simply rolls onto it.
            ? "Because {$this->target->name} is free, the change applies on {$ends} with nothing further to do."
            : "You will be billed at the {$this->target->name} price, not the {$current} one, "
                .'when you renew on or after that date.');

        return $mail
            ->action('Review your subscription', app(FrontendLinks::class)->subscription($notifiable))
            ->line("Changed your mind? Choose {$current} again on that page any time before {$ends} "
                .'and the move is called off.');
    }
}
