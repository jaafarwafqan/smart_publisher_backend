<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sprint 4 (Commercial SaaS): replaces Laravel's default
 * ResetPasswordNotification, which links to a `password.reset` web route
 * that doesn't exist in this API-only backend. Mirrors the pattern the
 * Facebook OAuth relay already established for handing control back to the
 * Flutter app via a custom URL scheme, and — per the user's explicit
 * decision this sprint — is delivered through the existing MAIL_MAILER=log
 * driver rather than a real mail provider, so the token below is read
 * straight out of storage/logs/laravel.log for now.
 */
class ApiPasswordResetNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = method_exists($notifiable, 'getEmailForPasswordReset')
            ? $notifiable->getEmailForPasswordReset()
            : (string) $notifiable->email;

        $deepLink = 'smartpublisher://password-reset?token='.$this->token.'&email='.urlencode($email);

        return (new MailMessage)
            ->subject('Reset your Smart Publisher password')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->line('Reset token: '.$this->token)
            ->action('Open Smart Publisher', $deepLink)
            ->line('Submit this token, your email, and a new password to POST /api/v1/auth/reset-password.')
            ->line('This password reset token will expire in 60 minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }
}
