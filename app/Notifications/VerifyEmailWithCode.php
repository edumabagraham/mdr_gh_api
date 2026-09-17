<?php

namespace App\Notifications;

use App\Models\EmailVerificationCode;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * The email a user receives right after registering.
 *
 * Deliverability notes — this message is deliberately plain:
 *  - no links and no images, the two things filters weigh most heavily;
 *  - Laravel renders a text/plain alternative alongside the HTML part, and a
 *    missing text part on its own costs spam points;
 *  - `Auto-Submitted` stops out-of-office responders replying to it;
 *  - `X-Entity-Ref-ID` keeps Gmail from collapsing repeat codes into one thread.
 *
 * The rest of deliverability is DNS, not code: see the MAIL_* block in
 * .env.example for the SPF/DKIM/DMARC records the sending domain needs.
 */
class VerifyEmailWithCode extends Notification
{
    use Queueable;

    public function __construct(public string $code) {}

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
            ->subject($this->code.' is your '.config('app.name').' verification code')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Enter this code on the verification page to finish setting up your account:')
            ->line('**'.$this->code.'**')
            ->line('The code expires in '.EmailVerificationCode::LIFETIME_MINUTES.' minutes.')
            ->line('If you did not create an account, you can safely ignore this email.')
            ->salutation('— The '.config('app.name').' team')
            ->withSymfonyMessage(function (Email $message): void {
                $headers = $message->getHeaders();
                $headers->addTextHeader('Auto-Submitted', 'auto-generated');
                $headers->addTextHeader('X-Entity-Ref-ID', (string) Str::uuid());
            });
    }
}
