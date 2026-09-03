<?php

namespace App\Notifications;

use App\Models\WordpressLoginEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UnauthorizedWordpressLoginNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly WordpressLoginEvent $loginEvent)
    {
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
        $login = $this->loginEvent;

        return (new MailMessage)
            ->subject("Unauthorized WP-Admin login detected on {$login->site_name}")
            ->error()
            ->line("A wp-admin login was recorded from an IP address that is not on the whitelist for {$login->site_name}.")
            ->line("User: {$login->username}")
            ->line("IP address: {$login->ip_address}")
            ->line("Time: {$login->logged_in_at->toDayDateTimeString()}")
            ->line('If this was not expected, review the site\'s IP whitelist and rotate its credentials.');
    }
}
