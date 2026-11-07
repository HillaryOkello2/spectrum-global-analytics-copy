<?php

namespace App\Notifications;

use App\Models\Product;
use App\Services\Access\FrontendLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * One new article, or a daily digest of several.
 */
class NewArticlePublished extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @var list<Product> */
    private array $products;

    /**
     * @param  Product|list<Product>|Collection<int, Product>  $products
     */
    public function __construct(
        Product|array|Collection $products,
        private readonly bool $digest = false,
    ) {
        $this->products = $products instanceof Product
            ? [$products]
            : array_values($products instanceof Collection ? $products->all() : $products);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $links = app(FrontendLinks::class);
        $unsubscribe = $links->unsubscribeNotifications($notifiable);

        if ($this->digest || count($this->products) > 1) {
            return $this->digestMail($notifiable, $links, $unsubscribe);
        }

        $product = $this->products[0]->loadMissing('component');

        $mail = (new MailMessage)
            ->subject('New: '.$product->title)
            ->greeting("Hello {$notifiable->first_name},")
            ->line('A new '.$product->component?->name.' article is available.')
            ->line('**'.$product->title.'**');

        if ($product->published_at !== null) {
            $mail->line('Published '.$product->published_at->format('j F Y').'.');
        }

        if (filled($product->abstract)) {
            $mail->line($product->abstract);
        }

        return $mail
            ->action('Read in the portal', $links->product($notifiable, $product))
            ->line('You can change how often you get these emails in your profile.')
            ->line('[Unsubscribe from article emails]('.$unsubscribe.')');
    }

    private function digestMail(object $notifiable, FrontendLinks $links, string $unsubscribe): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Today\'s new articles from Spectrum')
            ->greeting("Hello {$notifiable->first_name},")
            ->line('Here are the new articles published for you:');

        foreach ($this->products as $product) {
            $product->loadMissing('component');
            $mail->line(
                '• **'.$product->title.'** ('
                .($product->component?->name ?? 'Article')
                .') — [Open]('.$links->product($notifiable, $product).')'
            );
            if (filled($product->abstract)) {
                $mail->line(str($product->abstract)->limit(200)->value());
            }
        }

        return $mail
            ->action('Open the portal', $links->subscription($notifiable))
            ->line('[Unsubscribe from article emails]('.$unsubscribe.')');
    }
}
