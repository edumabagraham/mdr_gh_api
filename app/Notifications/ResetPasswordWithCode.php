<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * The email a user receives after asking to reset a forgotten password.
 *
 * Carries a code rather than a signed link, so the reset is completed on the
 * same device and tab the request started in. See {@see VerifyEmailWithCode}
 * for why the message is kept this plain.
 */
class ResetPasswordWithCode extends Notification
{
    use Queueable;

    public function __construct(public string $code, public int $expiresInMinutes) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->code.' is your '.config('app.name').' password reset code')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Use this code to choose a new password:')
            ->line('**'.$this->code.'**')
            ->line('The code expires in '.$this->expiresInMinutes.' minutes.')
            ->line('If you did not ask to reset your password, ignore this email — your password stays as it is.')
            ->salutation('— The '.config('app.name').' team')
            ->withSymfonyMessage(function (Email $message): void {
                $headers = $message->getHeaders();
                $headers->addTextHeader('Auto-Submitted', 'auto-generated');
                $headers->addTextHeader('X-Entity-Ref-ID', (string) Str::uuid());
            });
    }
}
