<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Subscription;
use App\Services\SubscriptionNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionExpiryWarning extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function __construct(private readonly Subscription $subscription) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = $this->subscription->company;
        $notice = $company ? app(SubscriptionNotice::class)->for($company, $this->subscription) : null;
        $category = in_array($notice['category'] ?? null, ['trial', 'test'], true) ? $notice['category'] : 'paid';
        $date = (string) ($notice['ends_on'] ?? '');
        $key = (string) ($notice['key'] ?? '');
        $line = match (true) {
            $key === "{$category}.ends" => __("subscription.mail.ends.{$category}", ['days' => trans_choice('subscription.days', (int) $notice['params']['days']), 'date' => $date]),
            $key === "{$category}.today" => __("subscription.mail.today.{$category}", ['date' => $date]),
            default => __('subscription.mail.generic', ['date' => $date]),
        };

        return (new MailMessage)
            ->subject(__("subscription.mail.subject.{$category}"))
            ->greeting(__('subscription.mail.greeting', ['name' => $notifiable->name]))
            ->line($line)
            ->line(__('subscription.mail.action'));
    }
}
