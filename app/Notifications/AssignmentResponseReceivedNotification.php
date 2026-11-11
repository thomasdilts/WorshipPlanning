<?php

namespace App\Notifications;

use App\Enums\AssignmentState;
use App\Models\EventTask;
use App\Models\User;
use Illuminate\Notifications\Notification;

class AssignmentResponseReceivedNotification extends Notification
{
    public function __construct(
        public EventTask $task,
        public User $responder,
        public AssignmentState $state,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $task = $this->task->loadMissing('event');

        return [
            'event_task_id' => $task->id,
            'event_id' => $task->event_id,
            'event_name' => $task->event?->name,
            'task_name' => $task->displayName(),
            'responder_id' => $this->responder->id,
            'responder_name' => $this->responder->displayName(),
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'message' => __(':person responded :state for :task on :event', [
                'person' => $this->responder->displayName(),
                'state' => $this->state->label(),
                'task' => $task->displayName(),
                'event' => $task->event?->name ?? __('service'),
            ]),
        ];
    }
}
