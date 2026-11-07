<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Services\Access\FrontendLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when a paid term has lapsed. Usually the account is now on Freemium;
 * only a still-unpaid scheduled paid downgrade leaves access closed.
 */
class SubscriptionEnded extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly Subscription $subscription,
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
        $onFree = $subscription->tier?->isFree() ?? false;
        $from = $subscription->downgraded_from_tier_name;
        $ended = $subscription->downgraded_from_ended_at
            ?? $subscription->ends_at;

        $mail = (new MailMessage)
            ->subject($onFree ? 'Your subscription moved to Freemium' : 'Your subscription has ended')
            ->greeting("Hello {$notifiable->first_name},");

        if ($onFree) {
            $previous = $from ?? 'paid';
            $mail->line(
                "Your {$previous} subscription ended on "
                .($ended?->format('j F Y') ?? 'the end of its term')
                .', and your account is now on '.$subscription->tier?->name.'.'
            )
                ->line('You keep Freemium access to the catalogue. Upgrade any time to restore your previous plan.')
                ->action('View plans & upgrade', app(FrontendLinks::class)->subscription($notifiable));
        } else {
            $mail->line(
                "Your {$subscription->tier?->name} subscription ended on "
                .($subscription->ends_at?->format('j F Y') ?? 'the end of its term')
                .', and access to the paid catalogue has closed until you renew.'
            )
                ->line('Your account and your invoices remain; renewing restores access immediately.')
                ->action('Renew your subscription', app(FrontendLinks::class)->subscription($notifiable));
        }

        return $mail->line('Thank you for having been with us.');
    }
}
