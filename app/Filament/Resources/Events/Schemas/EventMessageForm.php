<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Enums\AssignmentState;
use App\Enums\MessageTemplatePurpose;
use App\Filament\Forms\MessageTemplateFields;
use App\Models\Event;
use App\Models\EventMessage;
use App\Models\EventTask;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\AssignmentStateResolver;
use App\Services\InvitationDeliveryPreview;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;
use Modules\Messaging\Services\EventMessageService;
use Spatie\Permission\PermissionRegistrar;

/**
 * The "add message" form for an event — used on the messages page and after editing a task.
 */
class EventMessageForm
{
    /**
     * @return array<int, Component>
     */
    public static function components(Event $event): array
    {
        $taskIds = static::unacceptedTaskIds($event);
        $participants = $event->personTaskOptions()->only($taskIds);
        $teams = $event->teamTaskOptions()->only($taskIds);

        return [
            ...MessageTemplateFields::components(MessageTemplatePurpose::Service, singlePreview: true),
            Placeholder::make('no_unaccepted_tasks')
                ->hiddenLabel()
                ->content(__('No assignments awaiting acceptance.'))
                ->visible($participants->isEmpty() && $teams->isEmpty()),
            CheckboxList::make('recipient_task_ids')
                ->label(__('People'))
                ->options($participants->all())
                ->columns(2)
                ->live()
                ->helperText($participants->isEmpty()
                    ? __('No people assigned on this service yet.')
                    : __('Only tasks that have not been accepted are shown. One message is sent for each selected task.'))
                ->visible($participants->isNotEmpty())
                ->bulkToggleable(),
            CheckboxList::make('team_task_ids')
                ->label(__('Teams'))
                ->options($teams->all())
                ->columns(2)
                ->live()
                ->helperText(__('Messages to a team are delivered to that team’s leader(s) only.'))
                ->visible($teams->isNotEmpty())
                ->bulkToggleable(),
            ViewField::make('recipient_preview')
                ->label(__('Who will receive this'))
                ->view('filament.messaging.recipient-preview')
                ->viewData(function (Get $get) use ($event): array {
                    return [
                        'rows' => app(InvitationDeliveryPreview::class)->resolveTasks(
                            $event,
                            static::eligibleSelectedTaskIds($event, $get('recipient_task_ids'), $get('team_task_ids')),
                        ),
                    ];
                })
                ->dehydrated(false)
                ->columnSpanFull(),
        ];
    }

    /**
     * Creates and immediately sends one message per selected person and task; returns the first for the create action.
     * The chosen template is used as saved, with {{custom_mess}} replaced by the typed custom message.
     *
     * @param  array<string, mixed>  $data
     */
    public static function create(Event $event, array $data): EventMessage
    {
        abort_unless(static::canSend(), 403);

        $template = MessageTemplate::query()->findOrFail($data['message_template_id'] ?? null);
        $taskIds = static::eligibleSelectedTaskIds($event, $data['recipient_task_ids'] ?? [], $data['team_task_ids'] ?? []);

        if ($taskIds === []) {
            throw ValidationException::withMessages([
                'recipient_task_ids' => __('Select at least one task that has not been accepted.'),
            ]);
        }

        return app(EventMessageService::class)->create(
            event: $event,
            author: auth()->user(),
            body: $template->body,
            subject: $template->subject,
            templateId: $template->id,
            sendNow: true,
            textBody: $template->text_body,
            recipientTaskIds: $taskIds,
            customMessage: $data['custom_message'] ?? null,
        )->first();
    }

    /**
     * @param  array<int, int|string>|null  $personTaskIds
     * @param  array<int, int|string>|null  $teamTaskIds
     * @return list<int>
     */
    protected static function selectedTaskIds(?array $personTaskIds, ?array $teamTaskIds): array
    {
        return array_values(array_unique(array_map('intval', [...($personTaskIds ?? []), ...($teamTaskIds ?? [])])));
    }

    /**
     * @return list<int>
     */
    public static function unacceptedTaskIds(Event $event): array
    {
        $tasks = $event->tasks()->with('responses')->get();
        $states = app(AssignmentStateResolver::class)->forMany($tasks);

        return $tasks
            ->reject(fn (EventTask $task): bool => in_array($states[$task->id], [
                AssignmentState::Vacant,
                AssignmentState::Confirmed,
                AssignmentState::ConfirmedVerbally,
            ], true))
            ->modelKeys();
    }

    /**
     * @param  array<int, int|string>|null  $personTaskIds
     * @param  array<int, int|string>|null  $teamTaskIds
     * @return list<int>
     */
    protected static function eligibleSelectedTaskIds(Event $event, ?array $personTaskIds, ?array $teamTaskIds): array
    {
        return array_values(array_intersect(
            static::selectedTaskIds($personTaskIds, $teamTaskIds),
            static::unacceptedTaskIds($event),
        ));
    }

    public static function canSend(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->church_id === null) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->church_id);

        return $user->can('messaging.send');
    }
}
