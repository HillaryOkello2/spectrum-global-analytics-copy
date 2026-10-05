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
 * Sent when a term has actually lapsed and access has closed.
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

        return (new MailMessage)
            ->subject('Your subscription has ended')
            ->greeting("Hello {$notifiable->first_name},")
            ->line("Your {$subscription->tier?->name} subscription ended on "
                .$subscription->ends_at?->format('j F Y').', and your access to the catalogue has closed.')
            ->line('Your account and your invoices remain; renewing restores access immediately.')
            ->action('Renew your subscription', app(FrontendLinks::class)->subscription($notifiable))
            ->line('Thank you for having been with us.');
    }
}
