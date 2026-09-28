<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        // Формируем ссылку с префиксом /app/
        $url = url('/app/password-reset/' . $this->token . '?email=' . urlencode($notifiable->getEmailForPasswordReset()));

        return (new MailMessage)
            ->subject('Сброс пароля')
            ->line('Вы получили это письмо, потому что мы получили запрос на сброс пароля для вашей учетной записи.')
            ->action('Сбросить пароль', $url)
            ->line('Ссылка действительна 60 минут.')
            ->line('Если вы не запрашивали сброс пароля, никаких действий не требуется.');
    }
}
