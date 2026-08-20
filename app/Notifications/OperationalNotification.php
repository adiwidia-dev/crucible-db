<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OperationalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    /**
     * @param  array{event:string,severity:'info'|'success'|'warning'|'critical',title:string,message:string,action_label:string,url:string,request_id?:int,connection_count?:int,statement_position?:int,session_id?:int,connection_id?:int}  $payload
     */
    public function __construct(
        private readonly array $payload,
        private readonly bool $sendDatabase,
        private readonly bool $sendMail,
    ) {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return array_filter([
            $this->sendDatabase ? 'database' : null,
            $this->sendMail ? 'mail' : null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return [
            'database' => 'notifications',
            'mail' => 'mail',
        ];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->payload['title'].' · Crucible DB')
            ->line($this->payload['message'])
            ->action($this->payload['action_label'], $this->payload['url'])
            ->line('Open Crucible DB to view this governed database operation.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}
