<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Enums\MessageTemplatePurpose;
use App\Filament\Forms\MessageTemplateFields;
use App\Models\Event;
use App\Models\EventMessage;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\InvitationDeliveryPreview;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
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
        $participants = $event->personTaskOptions();
        $teams = $event->teamTaskOptions();

        return [
            ...MessageTemplateFields::components(MessageTemplatePurpose::Service),
            CheckboxList::make('recipient_task_ids')
                ->label(__('People'))
                ->options($participants->all())
                ->columns(2)
                ->live()
                ->helperText($participants->isEmpty()
                    ? __('No people assigned on this event yet.')
                    : __('One line per task. Someone with several tasks gets a separate message for each task you tick.'))
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
                            static::selectedTaskIds($get('recipient_task_ids'), $get('team_task_ids')),
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

        return app(EventMessageService::class)->create(
            event: $event,
            author: auth()->user(),
            body: $template->body,
            subject: $template->subject,
            templateId: $template->id,
            sendNow: true,
            textBody: $template->text_body,
            recipientTaskIds: static::selectedTaskIds($data['recipient_task_ids'] ?? [], $data['team_task_ids'] ?? []),
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
