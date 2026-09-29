<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionExpiryWarning extends Notification
{
    use Queueable;

    public function __construct(private readonly Subscription $subscription) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('E-Zaki ERP subscription expiry reminder')
            ->greeting('Hello '.$notifiable->name)
            ->line('Your company subscription is scheduled to expire on '.$this->subscription->ends_at?->toDateString().'.')
            ->line('Please contact your platform administrator to arrange renewal.');
    }
}
