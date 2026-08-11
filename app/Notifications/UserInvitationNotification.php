<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class UserInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(private readonly string $token)
    {
        $this->afterCommit();
    }

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
        /** @var User $notifiable */
        return (new MailMessage)
            ->subject('You have been invited to Crucible DB')
            ->greeting("Hello {$notifiable->name},")
            ->line('An administrator has invited you to Crucible DB.')
            ->line('Accept the invitation to verify your email and set your password. You will not have database access until an administrator assigns roles to your account.')
            ->action('Accept invitation', $this->invitationUrl($notifiable))
            ->line('This invitation link expires in 7 days.');
    }

    public function invitationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'users.invitations.show',
            now()->addDays(7),
            ['user' => $user->id, 'token' => $this->token],
        );
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
