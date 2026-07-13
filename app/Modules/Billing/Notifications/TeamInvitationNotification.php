<?php

namespace App\Modules\Billing\Notifications;

use App\Modules\Billing\Models\TeamInvitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation email (ADR-0021). Carries the ONLY copy of the plaintext
 * token (the database stores its hash). Sent on-demand to the invited
 * address (Notification::route('mail', …)) — no User exists yet.
 */
class TeamInvitationNotification extends Notification
{
    public function __construct(
        private readonly TeamInvitation $invitation,
        private readonly string $plaintextToken,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // tenant_id is NOT NULL — the relation always resolves.
        $tenantName = $this->invitation->tenant->name;

        return (new MailMessage)
            ->subject("You've been invited to join {$tenantName}")
            ->greeting('Hello,')
            ->line("You have been invited to join the {$tenantName} team as {$this->invitation->role->label()}.")
            ->action('Accept invitation', route('invitations.show', ['token' => $this->plaintextToken]))
            ->line('The invitation expires '.$this->invitation->expires_at->toFormattedDateString().' and can be used once.')
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }
}
