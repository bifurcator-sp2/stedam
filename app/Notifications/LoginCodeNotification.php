<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoginCodeNotification extends Notification
{
    use Queueable;

    public function __construct(public string $code)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail/login_code.subject'))
            ->greeting(__('mail/login_code.greeting', ['name' => $notifiable->name]))
            ->line(__('mail/login_code.requested'))
            ->line(__('mail/login_code.code_text'))
            ->line('**' . $this->code . '**') // Код оставляем без перевода, так как это цифры
            ->line(__('mail/login_code.validity'))
            ->line(__('mail/login_code.ignore'))
            ->salutation(__('mail/login_code.salutation', ['app' => config('app.name')]));
    }
}
