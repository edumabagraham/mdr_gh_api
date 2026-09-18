<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one time the invitation token exists in readable form.
 *
 * It is not written to the database, not logged, and not returned by the API
 * that created it — this email is the only copy.
 */
class InvitationIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = [10, 60];

    public function __construct(
        public Invitation $invitation,
        public string $token,
        public string $invitedByName,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/invitations/accept?token='.$this->token;

        return (new MailMessage)
            ->subject('You have been invited to the '.config('app.name').' registry')
            ->greeting('Hello,')
            ->line($this->invitedByName.' has invited you to the Movement Disorder Registry at Komfo Anokye Teaching Hospital, as '.str_replace('_', ' ', $this->invitation->role).'.')
            ->action('Set up your account', $url)
            ->line('The invitation expires on '.$this->invitation->expires_at->toDayDateTimeString().'.')
            ->line('If you were not expecting this, ignore it and tell the registry administrator.')
            ->salutation('— The '.config('app.name').' team');
    }
}
