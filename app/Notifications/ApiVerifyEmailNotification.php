<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Sprint 4 (Commercial SaaS): replaces Laravel's default VerifyEmail
 * notification, which links to a `verification.verify` route rendered for
 * a browser. This backend registers that same named route (see
 * routes/api.php) as a plain JSON API endpoint instead, so the signed URL
 * Laravel already knows how to build (URL::temporarySignedRoute) works
 * unmodified — only the mail content changes, matching the same
 * log-mailer, deep-link pattern already used for password reset.
 */
class ApiVerifyEmailNotification extends Notification
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verifyUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );

        return (new MailMessage)
            ->subject('Verify your Smart Publisher email address')
            ->line('Please confirm this is your email address to finish setting up your Smart Publisher account.')
            ->action('Verify Email Address', $verifyUrl)
            ->line('This link will expire in '.(int) config('auth.verification.expire', 60).' minutes.')
            ->line('If you did not create an account, no further action is required.');
    }
}
