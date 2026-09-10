<?php

namespace App\Notifications;

use App\Services\Access\FrontendLinks;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Sent to a staff member when an admin creates their account, carrying the
 * password the admin set so they can sign in and then change it.
 *
 * Deliberately not queued: QUEUE_CONNECTION is `database` and no worker runs
 * that queue for mail, so a queued notification would sit in `jobs` and the
 * new user would never hear about their account.
 */
class AccountCreated extends Notification
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $password,
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
        return (new MailMessage)
            ->subject('Your '.config('app.name').' account')
            ->greeting("Hello {$notifiable->first_name},")
            ->line('An account has been created for you. Sign in with:')
            ->line(new HtmlString('<strong>Email:</strong> '.$this->literal($notifiable->email)))
            ->line(new HtmlString('<strong>Temporary password:</strong> '.$this->literal($this->password)))
            ->action('Sign in', app(FrontendLinks::class)->login($notifiable))
            ->line('Please change this password after you sign in, from your profile.')
            ->line('If you were not expecting this email, you can ignore it.');
    }

    /**
     * A value shown exactly as typed. Mail is rendered through Markdown, which
     * would read * _ ` ~ [ ] \ in a password as formatting and silently show
     * the user a different password from the one that works. e() makes it safe
     * as HTML; the numeric entities keep the Markdown pass from touching it.
     */
    private function literal(string $value): string
    {
        $escaped = strtr(e($value), [
            '*' => '&#42;',
            '_' => '&#95;',
            '`' => '&#96;',
            '~' => '&#126;',
            '[' => '&#91;',
            ']' => '&#93;',
            '\\' => '&#92;',
        ]);

        return '<code>'.$escaped.'</code>';
    }
}
