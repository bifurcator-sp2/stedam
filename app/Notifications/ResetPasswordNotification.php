<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = url('/app/password-reset/' . $this->token . '?email=' . urlencode($notifiable->getEmailForPasswordReset()));

        return (new MailMessage)
            ->subject(__('mail/reset.subject'))
            ->line(__('mail/reset.line1'))
            ->action(__('mail/reset.action'), $url)
            ->line(__('mail/reset.line2'))
            ->line(__('mail/reset.line3'))
            ->salutation(__('mail/verify.salutation', ['app' => config('app.name')])); // Используем салют из verify или можно добавить свой
    }
}
