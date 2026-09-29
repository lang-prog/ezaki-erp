<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegistrationVerificationNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $verificationUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your E-Zaki ERP registration')
            ->line('Confirm your email address to complete your company registration request.')
            ->action('Verify email', $this->verificationUrl)
            ->line('This link expires in one hour.');
    }
}
