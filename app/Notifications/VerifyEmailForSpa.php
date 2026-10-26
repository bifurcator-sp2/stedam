<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class VerifyEmailForSpa extends BaseVerifyEmail
{
    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify.api',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id'   => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }

    public function toMail($notifiable): MailMessage
    {
        $url = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject(__('mail/verify.subject')) // 👈 Слэш
            ->greeting(__('mail/verify.greeting', ['name' => $notifiable->name])) // 👈 Слэш
            ->line(__('mail/verify.line1')) // 👈 Слэш
            ->action(__('mail/verify.action'), $url) // 👈 Слэш
            ->line(__('mail/verify.line2')) // 👈 Слэш
            ->salutation(__('mail/verify.salutation', ['app' => config('app.name')])); // 👈 Слэш
    }
}
