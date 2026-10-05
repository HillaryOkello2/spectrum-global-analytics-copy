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
 * Sent while a subscription still has days to run (see
 * RemindExpiringSubscriptionsCommand), so renewing is a choice rather than a
 * discovery that the catalogue has locked.
 */
class SubscriptionEndingSoon extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly Subscription $subscription,
        private readonly int $daysLeft,
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

        return (new MailMessage)
            ->subject($this->daysLeft <= 1
                ? 'Your subscription ends tomorrow'
                : "Your subscription ends in {$this->daysLeft} days")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("Your {$subscription->tier?->name} subscription runs until {$ends}.")
            ->line('Renew before then and the new month is added to the date above, so nothing is lost by renewing early.')
            ->action('Renew your subscription', app(FrontendLinks::class)->subscription($notifiable))
            ->line('If you would rather not continue, you need do nothing.');
    }
}
