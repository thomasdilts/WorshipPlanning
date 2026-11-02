<?php

namespace App\Services;

use App\Enums\MessagePreference;
use App\Models\Church;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\User;
use Modules\Messaging\Support\MessagingAvailability;
use Modules\Messaging\Support\PhoneNumber;
use Modules\Teams\Services\TeamMessageService;

/**
 * Resolves who would receive an event message and on which channel — for preview only.
 */
class InvitationDeliveryPreview
{
    public function __construct(
        protected TeamMessageService $teams,
        protected MessagingAvailability $availability,
    ) {}

    /**
     * @param  list<int>  $recipientUserIds
     * @param  list<int>  $recipientTeamIds
     * @return list<array{user_id: int, name: string, channel: string, channel_label: string, via_team: ?string, can_deliver: bool, detail: ?string}>
     */
    public function resolve(Event $event, array $recipientUserIds, array $recipientTeamIds = []): array
    {
        $allowedUserIds = $event->participantUsers()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $allowedTeamIds = $event->participantTeams()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $recipientUserIds = array_values(array_intersect(array_map('intval', $recipientUserIds), $allowedUserIds));
        $recipientTeamIds = array_values(array_intersect(array_map('intval', $recipientTeamIds), $allowedTeamIds));

        $viaTeam = [];
        foreach ($event->participantTeams()->whereIn('id', $recipientTeamIds) as $team) {
            foreach ($this->teams->leaderIdsForTeams([$team->id]) as $leaderId) {
                $viaTeam[$leaderId] = $team->name;
            }
        }

        $userIds = array_values(array_unique([...$recipientUserIds, ...array_keys($viaTeam)]));

        if ($userIds === []) {
            return [];
        }

        $church = $event->church;

        return User::query()
            ->whereIn('id', $userIds)
            ->orderBy('display_name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'user_id' => $user->id,
                'name' => $user->displayName(),
                ...$this->channelFor($user, $church),
                'via_team' => $viaTeam[$user->id] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Who receives a message about each selected task: the task's person, or the leaders of its team.
     * Selected people (without a task choice) get one message per task they have on the event.
     *
     * @param  list<int>  $taskIds
     * @param  list<int>  $userIds
     * @param  list<int>  $teamIds
     * @return list<array{user: User, task: ?EventTask}>
     */
    public function recipientPairs(Event $event, array $taskIds, array $userIds = [], array $teamIds = []): array
    {
        $event->loadMissing(['tasks.assignedUser', 'tasks.assignedTeam', 'tasks.template']);
        $taskIds = array_map('intval', $taskIds);
        $teamIds = array_map('intval', $teamIds);
        $pairs = [];

        $add = function (User $user, ?EventTask $task) use (&$pairs): void {
            $pairs[$user->id.'-'.($task?->id ?? 0)] = ['user' => $user, 'task' => $task];
        };

        foreach ($event->tasks as $task) {
            $selected = in_array((int) $task->id, $taskIds, true)
                || (blank($task->assigned_user_id) && in_array((int) $task->assigned_team_id, $teamIds, true));

            if (! $selected) {
                continue;
            }

            if ($task->assignedUser !== null) {
                $add($task->assignedUser, $task);

                continue;
            }

            if (filled($task->assigned_team_id)) {
                foreach (User::query()->whereIn('id', $this->teams->leaderIdsForTeams([$task->assigned_team_id]))->get() as $leader) {
                    $add($leader, $task);
                }
            }
        }

        foreach ($event->participantUsers()->whereIn('id', array_map('intval', $userIds)) as $user) {
            $tasks = $event->tasksFor($user);

            foreach ($tasks->isEmpty() ? [null] : $tasks as $task) {
                $add($user, $task);
            }
        }

        return array_values($pairs);
    }

    /**
     * Preview rows for the selected tasks, one per person and task.
     *
     * @param  list<int>  $taskIds
     * @return list<array{user_id: int, name: string, task: ?string, channel: string, channel_label: string, via_team: ?string, can_deliver: bool, detail: ?string}>
     */
    public function resolveTasks(Event $event, array $taskIds): array
    {
        return collect($this->recipientPairs($event, $taskIds))
            ->map(fn (array $pair): array => [
                'user_id' => $pair['user']->id,
                'name' => $pair['user']->displayName(),
                'task' => $pair['task']?->displayName(),
                ...$this->channelFor($pair['user'], $event->church),
                'via_team' => $pair['task'] !== null && blank($pair['task']->assigned_user_id)
                    ? $pair['task']->assignedTeam?->name
                    : null,
            ])
            ->sortBy(fn (array $row): string => mb_strtolower($row['name'].' '.$row['task']))
            ->values()
            ->all();
    }

    /**
     * @return array{channel: string, channel_label: string, can_deliver: bool, detail: ?string}
     */
    protected function channelFor(User $user, ?Church $church): array
    {
        $preference = $user->message_preference ?? MessagePreference::Email;
        $channel = $preference;
        $canDeliver = $this->availability->isChannelEnabled($church, $channel);
        $detail = null;

        if (! $canDeliver && $channel !== MessagePreference::Email
            && $this->availability->isChannelEnabled($church, MessagePreference::Email)) {
            $channel = MessagePreference::Email;
            $canDeliver = true;
            $detail = __('Falls back to email');
        }

        if ($canDeliver && $channel === MessagePreference::Email && blank($user->email)) {
            $canDeliver = false;
            $detail = __('Missing email');
        }

        if ($canDeliver && in_array($channel, [MessagePreference::Sms, MessagePreference::WhatsApp], true) && PhoneNumber::toMsisdn($user->phone) === null) {
            $canDeliver = false;
            $detail = __('No valid mobile number');
        }

        if ($canDeliver && $channel === MessagePreference::Ntfy && blank($user->ntfy_topic)) {
            $canDeliver = false;
            $detail = __('No ntfy topic');
        }

        if ($canDeliver && $channel === MessagePreference::Telegram && blank($user->telegram_chat_id)) {
            $canDeliver = false;
            $detail = __('Telegram not connected');
        }

        return [
            'channel' => $channel->value,
            'channel_label' => $channel->label(),
            'can_deliver' => $canDeliver,
            'detail' => $detail,
        ];
    }

    /**
     * Deduplicated recipient user ids (people + team leaders).
     *
     * @param  list<int>  $recipientUserIds
     * @param  list<int>  $recipientTeamIds
     * @return list<int>
     */
    public function recipientUserIds(Event $event, array $recipientUserIds, array $recipientTeamIds = []): array
    {
        return collect($this->resolve($event, $recipientUserIds, $recipientTeamIds))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
