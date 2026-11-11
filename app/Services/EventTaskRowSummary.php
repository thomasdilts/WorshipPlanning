<?php

namespace App\Services;

use App\Core\Modules\ChurchModuleManager;
use App\Enums\AssignmentState;
use App\Models\Event;
use App\Models\EventTask;
use App\Support\AnnouncementPoints;
use App\Support\UserAvatar;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Modules\Unavailability\Services\UnavailabilityConflictService;

/**
 * Compact service-order cell: filled fields + missing required + conflicts.
 */
class EventTaskRowSummary
{
    public function __construct(
        protected ChurchModuleManager $modules,
        protected UnavailabilityConflictService $unavailability,
        protected AssignmentStateResolver $states,
    ) {}

    public function html(EventTask $task, Event $event): HtmlString
    {
        $task->loadMissing([
            'template',
            'assignedUser',
            'assignedTeam',
            'sharedFiles',
            'pictures',
            'catalogSong',
        ]);

        $event->loadMissing(['church']);

        $bits = $this->filledBits($task, $event, withAvatarHtml: true);
        $problems = $this->problems($task, $event);

        $parts = [];

        foreach ($bits as $bit) {
            $parts[] = '<div class="wp-task-detail">'
                .($bit instanceof HtmlString ? $bit->toHtml() : e($bit))
                .'</div>';
        }

        foreach ($problems as $problem) {
            $parts[] = '<div class="wp-task-problem">'.e($problem).'</div>';
        }

        if ($parts === []) {
            return new HtmlString('<span class="wp-task-detail-empty">—</span>');
        }

        return new HtmlString('<div class="wp-task-summary">'.implode('', $parts).'</div>');
    }

    /**
     * @return list<string|HtmlString>
     */
    public function filledBits(EventTask $task, Event $event, bool $withAvatarHtml = false, bool $forPrint = false): array
    {
        $template = $task->template;
        $bits = [];

        if (filled($task->description) && ($template?->usage('description')->isUsed() ?? true)) {
            $bits[] = ($withAvatarHtml || $forPrint) && AnnouncementPoints::appliesTo($task->name, $template?->name)
                ? new HtmlString(view('filament.events.announcement-points', ['points' => AnnouncementPoints::fromDescription($task->description)])->render())
                : $task->description;
        }

        if (filled($task->special_needs) && ($template?->usage('special_needs')->isUsed() ?? true)) {
            $bits[] = __('Needs: ').$task->special_needs;
        }

        if ($this->songPresent($task) && ($template?->usage('song')->isUsed() ?? true)) {
            $bits[] = __('Song: ').$this->songLabel($task);
        }

        if ($task->assignedUser !== null && ($template?->usage('person')->isUsed() ?? true)) {
            $bits[] = $withAvatarHtml
                ? new HtmlString(UserAvatar::html($task->assignedUser)->toHtml().$this->statusHtml($task))
                : $task->assignedUser->displayName();
        }

        if ($task->typedPersonName() !== null && ($template?->usage('person')->isUsed() ?? true)) {
            $bits[] = $task->typedPersonName();
        }

        if (
            $this->moduleEnabled($event, 'teams')
            && $task->assignedTeam !== null
            && ($template?->usage('team')->isUsed() ?? true)
        ) {
            $bits[] = $withAvatarHtml && $task->assignedUser === null
                ? new HtmlString(e(__('Team: ').$task->assignedTeam->name).$this->statusHtml($task))
                : __('Team: ').$task->assignedTeam->name;
        }

        if (
            $this->moduleEnabled($event, 'teams')
            && $task->typedTeamName() !== null
            && ($template?->usage('team')->isUsed() ?? true)
        ) {
            $bits[] = __('Team: ').$task->typedTeamName();
        }

        if (
            $this->moduleEnabled($event, 'file_sharing')
            && ($template?->usage('file')->isUsed() ?? false)
            && $task->sharedFiles->isNotEmpty()
        ) {
            $names = $task->sharedFiles->pluck('name')->filter()->implode(', ');
            $bits[] = __('Files: ').$names;
        }

        return $bits;
    }

    /**
     * Response chip shown beside the assignee: No response, Accepted, Declined, Accepted verbally, …
     */
    protected function statusHtml(EventTask $task): string
    {
        $state = $this->states->for($task);

        if (in_array($state, [AssignmentState::Vacant, AssignmentState::AssumedTeam, AssignmentState::Named], true)) {
            return '';
        }

        return ' '.Blade::render('<x-assignment-status :state="$state" />', ['state' => $state]);
    }

    /**
     * Missing required fields + unavailability conflicts.
     *
     * @return list<string>
     */
    public function problems(EventTask $task, Event $event, bool $forPrint = false): array
    {
        $template = $task->template;
        $problems = [];

        if ($template === null) {
            return $problems;
        }

        if ($this->missingRequiredPerson($task, $event)) {
            $problems[] = __('Missing: Person');
        }

        if ($this->missingRequiredTeam($task, $event)) {
            $problems[] = __('Missing: Team');
        }

        if (! $forPrint && $template->usage('description')->isRequired() && blank($task->description)) {
            $problems[] = __('Missing: Description');
        }

        if ($template->usage('special_needs')->isRequired() && blank($task->special_needs)) {
            $problems[] = __('Missing: Special needs');
        }

        if ($template->usage('song')->isRequired() && ! $this->songPresent($task)) {
            $problems[] = __('Missing: Song');
        }

        if (
            $this->moduleEnabled($event, 'file_sharing')
            && $template->usage('file')->isRequired()
            && $task->sharedFiles->isEmpty()
        ) {
            $problems[] = __('Missing: Files');
        }

        if (
            $this->moduleEnabled($event, 'pictures')
            && $template->usage('picture')->isRequired()
            && $task->pictures->isEmpty()
        ) {
            $problems[] = __('Missing: Pictures');
        }

        if (
            $this->moduleEnabled($event, 'bible_verses')
            && $template->usage('bible')->isRequired()
            && empty($task->bible_passages)
        ) {
            $problems[] = __('Missing: Bible verses');
        }

        if (! $forPrint && $this->assignedUserUnavailable($task, $event)) {
            $name = $task->assignedUser->display_name ?: $task->assignedUser->name;
            $problems[] = __(':name is unavailable', ['name' => $name]);
        }

        if (! $forPrint && $this->assignedTeamUnavailable($task, $event)) {
            $problems[] = __(':name is unavailable', ['name' => $task->assignedTeam->name]);
        }

        return $problems;
    }

    /**
     * Staffing = person/team assignment issues. Other = remaining required fields.
     *
     * @return array{staffing: bool, other: bool}
     */
    public function problemFlags(EventTask $task, Event $event): array
    {
        $template = $task->template;

        if ($template === null) {
            return ['staffing' => false, 'other' => false];
        }

        $staffing = $this->missingRequiredPerson($task, $event)
            || $this->missingRequiredTeam($task, $event)
            || $this->assignedUserUnavailable($task, $event)
            || $this->assignedTeamUnavailable($task, $event);

        $other = ($template->usage('description')->isRequired() && blank($task->description))
            || ($template->usage('special_needs')->isRequired() && blank($task->special_needs))
            || ($template->usage('song')->isRequired() && ! $this->songPresent($task))
            || (
                $this->moduleEnabled($event, 'file_sharing')
                && $template->usage('file')->isRequired()
                && $task->sharedFiles->isEmpty()
            )
            || (
                $this->moduleEnabled($event, 'pictures')
                && $template->usage('picture')->isRequired()
                && $task->pictures->isEmpty()
            )
            || (
                $this->moduleEnabled($event, 'bible_verses')
                && $template->usage('bible')->isRequired()
                && empty($task->bible_passages)
            );

        return [
            'staffing' => $staffing,
            'other' => $other,
        ];
    }

    /**
     * Aggregate problem flags across every task on the event.
     *
     * @return array{staffing: bool, other: bool}
     */
    public function eventProblemFlags(Event $event): array
    {
        $event->loadMissing([
            'church',
            'tasks.template',
            'tasks.assignedUser',
            'tasks.assignedTeam',
            'tasks.sharedFiles',
            'tasks.pictures',
            'tasks.catalogSong',
        ]);

        $staffing = false;
        $other = false;

        foreach ($event->tasks as $task) {
            $flags = $this->problemFlags($task, $event);
            $staffing = $staffing || $flags['staffing'];
            $other = $other || $flags['other'];

            if ($staffing && $other) {
                break;
            }
        }

        return [
            'staffing' => $staffing,
            'other' => $other,
        ];
    }

    protected function missingRequiredPerson(EventTask $task, Event $event): bool
    {
        $template = $task->template;

        return $template !== null
            && $template->usage('person')->isRequired()
            && $task->assigned_user_id === null
            && $task->typedPersonName() === null;
    }

    protected function missingRequiredTeam(EventTask $task, Event $event): bool
    {
        $template = $task->template;

        return $template !== null
            && $this->moduleEnabled($event, 'teams')
            && $template->usage('team')->isRequired()
            && $task->assigned_team_id === null
            && $task->typedTeamName() === null;
    }

    protected function assignedUserUnavailable(EventTask $task, Event $event): bool
    {
        if ($task->assignedUser === null) {
            return false;
        }

        $window = $this->unavailability->windowForTask($task, $event);

        return $this->unavailability->userIsUnavailable(
            $task->assignedUser,
            $window['from'],
            $window['to'],
            $event->church,
        );
    }

    protected function assignedTeamUnavailable(EventTask $task, Event $event): bool
    {
        if ($task->assignedTeam === null) {
            return false;
        }

        $window = $this->unavailability->windowForTask($task, $event);

        return $this->unavailability->teamIsUnavailable(
            $task->assignedTeam,
            $window['from'],
            $window['to'],
            $event->church,
        );
    }

    protected function songPresent(EventTask $task): bool
    {
        return filled($task->song_id) || filled(trim((string) ($task->song ?? '')));
    }

    protected function songLabel(EventTask $task): string
    {
        if ($task->catalogSong !== null) {
            return $task->catalogSong->label();
        }

        return (string) $task->song;
    }

    protected function moduleEnabled(Event $event, string $key): bool
    {
        $church = $event->church;

        if ($church === null) {
            return false;
        }

        return $this->modules->isEnabled($church, $key);
    }
}
