<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Core\Modules\ChurchModuleManager;
use App\Enums\FieldUsage;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\Song;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\User;
use App\Services\EventTaskTimeline;
use App\Services\MaterialAssignmentChange;
use App\Services\PlanningAttachmentSearch;
use App\Services\SongCatalogSearch;
use App\Support\AnnouncementPoints;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Modules\Abilities\Services\UserAbilities;
use Modules\BibleVerses\Services\ZamarBibleClient;
use Modules\BibleVerses\Support\OsisCanon;
use Modules\BibleVerses\Support\PassageRef;
use Modules\Unavailability\Services\UnavailabilityConflictService;

/**
 * Single source of truth for the event-task create/edit modal
 * (service order + task spreadsheet).
 */
class EventTaskForm
{
    public static function configure(Schema $schema, Event $event): Schema
    {
        return $schema
            ->dense()
            ->columns(['default' => 1, 'md' => 2])
            ->components(static::components($event));
    }

    /**
     * @return array<int, mixed>
     */
    public static function components(Event $event): array
    {
        $teamsEnabled = static::moduleEnabled($event, 'teams');
        $picturesEnabled = static::moduleEnabled($event, 'pictures');
        $fileSharingEnabled = static::moduleEnabled($event, 'file_sharing');
        $bibleVersesEnabled = static::moduleEnabled($event, 'bible_verses');
        $songListEnabled = static::moduleEnabled($event, 'song_list');
        $abilitiesEnabled = static::moduleEnabled($event, 'abilities');
        $defaultBible = $bibleVersesEnabled
            ? app(ZamarBibleClient::class)->settings($event->church)['default_bible']
            : 'kjv';

        return [
            Select::make('task_template_id')
                ->label(__('Task template'))
                ->options(fn (): array => TaskTemplate::query()
                    ->active()
                    ->orderBy('default_order')
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(function (?int $state, callable $set) use ($abilitiesEnabled): void {
                    if (! $state) {
                        return;
                    }

                    $template = TaskTemplate::query()->find($state);

                    if (! $template) {
                        return;
                    }

                    $set('name', $template->name);

                    if ($template->use_globally) {
                        $set('starts_at', null);
                        $set('ends_at', null);
                        $set('duration', null);
                        $set('sort_order', $template->default_global_order);
                    } else {
                        $set('duration', $template->default_duration ?: 5);
                        $set('sort_order', $template->default_order);
                        // Start/end are computed by EventTaskTimeline after save.
                        $set('starts_at', null);
                        $set('ends_at', null);
                    }

                    if ($template->usage('description')->isUsed()) {
                        $set('description', $template->description);
                    } else {
                        $set('description', null);
                    }

                    $set('announcement_points', AnnouncementPoints::fromDescription($template->description) ?: [['text' => '']]);

                    if (! $template->usage('person')->isUsed()) {
                        $set('assigned_user_id', null);
                    } elseif ($abilitiesEnabled) {
                        // Person options are ability-scoped per template — clear stale pick.
                        $set('assigned_user_id', null);
                    }

                    $set('assigned_team_id', null);

                    if (! $template->usage('song')->isUsed()) {
                        $set('song', null);
                        $set('song_id', null);
                    }

                    if (! $template->usage('picture')->isUsed()) {
                        $set('picture_ids', []);
                    }

                    if (! $template->usage('special_needs')->isUsed()) {
                        $set('special_needs', null);
                    }

                    if (! $template->usage('file')->isUsed()) {
                        $set('shared_file_ids', []);
                    }

                    if (! $template->usage('bible')->isUsed()) {
                        $set('bible_passages', []);
                    }
                })
                ->helperText(__('Templates are managed by church admins. You pick one and can override details for this service.')),
            TextInput::make('name')
                ->label(__('Title override'))
                ->live(onBlur: true)
                ->maxLength(100)
                ->helperText(__('Leave blank to use the template name.')),
            Textarea::make('description')
                ->label(__('Description'))
                ->rows(2)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (?string $state, Set $set) => $set('announcement_points', AnnouncementPoints::fromDescription($state) ?: [['text' => '']]))
                ->visible(fn (Get $get): bool => static::templateUsage($get, 'description')->isUsed() && ! static::isAnnouncement($get))
                ->markAsRequired(fn (Get $get): bool => static::templateUsage($get, 'description')->isRequired()),
            Repeater::make('announcement_points')
                ->label(__('Announcement points'))
                ->helperText(__('Write one announcement in each numbered box. Add as many points as you need; the PDF uses the same order.'))
                ->schema([
                    Textarea::make('text')
                        ->label(__('Announcement'))
                        ->hiddenLabel()
                        ->rows(2)
                        ->autosize(),
                ])
                ->itemLabel(__('Announcement'))
                ->itemNumbers()
                ->addActionLabel(__('Add announcement'))
                ->defaultItems(1)
                ->reorderableWithButtons()
                ->extraAttributes(['class' => 'wp-announcement-editor'])
                ->live(onBlur: true)
                ->afterStateUpdated(fn (?array $state, Set $set) => $set('description', AnnouncementPoints::toDescription($state ?? [])))
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => static::templateUsage($get, 'description')->isUsed() && static::isAnnouncement($get)),
            Select::make('song_id')
                ->label(__('Song from catalog'))
                ->searchable()
                ->getSearchResultsUsing(fn (?string $search): array => app(SongCatalogSearch::class)
                    ->options((int) $event->church_id, $search))
                ->getOptionLabelUsing(fn ($value): ?string => app(SongCatalogSearch::class)
                    ->labelFor($value ? (int) $value : null, (int) $event->church_id))
                ->options(fn (): array => app(SongCatalogSearch::class)
                    ->options((int) $event->church_id, null, 25))
                ->nullable()
                ->live()
                ->visible(function (Get $get) use ($songListEnabled): bool {
                    if (! $songListEnabled) {
                        return false;
                    }

                    return static::templateUsage($get, 'song')->isUsed();
                })
                // Asterisk only: blank is allowed so an assignment can be cleared or added later.
                ->markAsRequired(fn (Get $get): bool => static::songCatalogRequired($get, $songListEnabled))
                ->afterStateUpdated(function (?int $state, callable $set): void {
                    if (! $state) {
                        return;
                    }

                    $song = Song::query()->find($state);

                    if ($song) {
                        $set('song', $song->name);
                    }
                })
                ->helperText(function (Get $get): string {
                    if (static::templateUsage($get, 'song')->isRequired() && static::templateAllowsManualSong($get)) {
                        return __('Search the catalog (recent first), or type a one-off title below.');
                    }

                    return __('Search by name, alternate name, or author. Recently used songs appear first.');
                }),
            TextInput::make('song')
                ->label($songListEnabled ? __('Song title (one-off)') : __('Song'))
                ->maxLength(255)
                ->live()
                ->visible(function (Get $get) use ($songListEnabled): bool {
                    if (! static::templateUsage($get, 'song')->isUsed()) {
                        return false;
                    }

                    if (! $songListEnabled) {
                        return true;
                    }

                    return static::templateAllowsManualSong($get);
                })
                ->markAsRequired(fn (Get $get): bool => static::songManualRequired($get, $songListEnabled))
                ->afterStateUpdated(function (?string $state, callable $set, Get $get) use ($songListEnabled): void {
                    if (! $songListEnabled) {
                        return;
                    }

                    if (filled($state) && filled($get('song_id'))) {
                        $catalogName = Song::query()->find($get('song_id'))?->name;

                        if ($catalogName !== $state) {
                            $set('song_id', null);
                        }
                    }
                })
                ->helperText(function (Get $get) use ($songListEnabled): ?string {
                    if (! $songListEnabled) {
                        return null;
                    }

                    if (! static::templateAllowsManualSong($get)) {
                        return null;
                    }

                    return static::templateUsage($get, 'song')->isRequired()
                        ? __('Use when the song is not in the catalog yet.')
                        : __('Optional free-text title instead of a catalog song.');
                }),
            Select::make('picture_ids')
                ->label(__('Pictures'))
                ->multiple()
                ->searchable()
                ->getSearchResultsUsing(fn (?string $search): array => app(PlanningAttachmentSearch::class)
                    ->pictureOptions((int) $event->church_id, $search))
                ->getOptionLabelsUsing(fn (array $values): array => collect($values)
                    ->mapWithKeys(fn ($id): array => [
                        (int) $id => app(PlanningAttachmentSearch::class)
                            ->pictureLabel((int) $id, (int) $event->church_id) ?? (string) $id,
                    ])
                    ->all())
                ->options(fn (): array => app(PlanningAttachmentSearch::class)
                    ->pictureOptions((int) $event->church_id, null, 25))
                ->visible(function (Get $get) use ($picturesEnabled): bool {
                    if (! $picturesEnabled) {
                        return false;
                    }

                    return static::templateUsage($get, 'picture')->isUsed();
                })
                ->markAsRequired(function (Get $get) use ($picturesEnabled): bool {
                    if (! $picturesEnabled) {
                        return false;
                    }

                    return static::templateUsage($get, 'picture')->isRequired();
                })
                ->helperText(__('Search the picture library. Upload new ones under Library → Pictures.')),
            Select::make('shared_file_ids')
                ->label(__('Files'))
                ->multiple()
                ->searchable()
                ->getSearchResultsUsing(fn (?string $search): array => app(PlanningAttachmentSearch::class)
                    ->fileOptions((int) $event->church_id, $search))
                ->getOptionLabelsUsing(fn (array $values): array => collect($values)
                    ->mapWithKeys(fn ($id): array => [
                        (int) $id => app(PlanningAttachmentSearch::class)
                            ->fileLabel((int) $id, (int) $event->church_id) ?? (string) $id,
                    ])
                    ->all())
                ->options(fn (): array => app(PlanningAttachmentSearch::class)
                    ->fileOptions((int) $event->church_id, null, 25))
                ->visible(function (Get $get) use ($fileSharingEnabled): bool {
                    if (! $fileSharingEnabled) {
                        return false;
                    }

                    return static::templateUsage($get, 'file')->isUsed();
                })
                ->markAsRequired(function (Get $get) use ($fileSharingEnabled): bool {
                    if (! $fileSharingEnabled) {
                        return false;
                    }

                    return static::templateUsage($get, 'file')->isRequired();
                })
                ->helperText(__('Search shared files (church or team uploads).')),
            Textarea::make('special_needs')
                ->label(__('Special needs'))
                ->rows(2)
                ->visible(fn (Get $get): bool => static::templateUsage($get, 'special_needs')->isUsed())
                ->markAsRequired(fn (Get $get): bool => static::templateUsage($get, 'special_needs')->isRequired()),
            Repeater::make('bible_passages')
                ->label(__('Bible verses'))
                ->helperText(function () use ($event): string {
                    try {
                        $bibles = app(ZamarBibleClient::class)->bibles($event->church);

                        if ($bibles === []) {
                            return __('Bible module is on, but no translations are available yet. Check Bible settings.');
                        }
                    } catch (\Throwable) {
                        return __('Bible text could not be loaded (API unavailable). You can still save the reference.');
                    }

                    return __('Book → chapter → verse(s). Verse text loads from the configured Bible API.');
                })
                ->schema([
                    Select::make('bible')
                        ->label(__('Translation'))
                        ->options(function () use ($event): array {
                            try {
                                return collect(app(ZamarBibleClient::class)->bibles($event->church))
                                    ->mapWithKeys(fn (array $row): array => [$row['code'] => $row['name'].' ('.$row['code'].')'])
                                    ->all();
                            } catch (\Throwable) {
                                return filled($defaultBible) ? [$defaultBible => $defaultBible] : [];
                            }
                        })
                        ->default($defaultBible)
                        ->required()
                        ->native(false)
                        ->searchable()
                        ->live(),
                    Select::make('book')
                        ->label(__('Book'))
                        ->options(OsisCanon::bookOptions())
                        ->required()
                        ->native(false)
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (callable $set): void {
                            $set('chapter', null);
                            $set('verse_start', null);
                            $set('verse_end', null);
                        }),
                    Select::make('chapter')
                        ->label(__('Chapter'))
                        ->options(fn (Get $get): array => filled($get('book'))
                            ? OsisCanon::chapterOptions((string) $get('book'))
                            : [])
                        ->required()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function (callable $set): void {
                            $set('verse_start', null);
                            $set('verse_end', null);
                        }),
                    Select::make('verse_start')
                        ->label(__('From verse'))
                        ->options(fn (Get $get): array => OsisCanon::verseOptions((string) ($get('book') ?? 'PS')))
                        ->required()
                        ->native(false)
                        ->live(),
                    Select::make('verse_end')
                        ->label(__('To verse'))
                        ->options(fn (Get $get): array => OsisCanon::verseOptions((string) ($get('book') ?? 'PS')))
                        ->native(false)
                        ->nullable()
                        ->live()
                        ->helperText(__('Leave empty for a single verse.')),
                    Placeholder::make('passage_text')
                        ->label(__('Verse text'))
                        ->content(fn (Get $get): string => static::biblePassagePreview($get, $event))
                        ->columnSpanFull(),
                ])
                ->columns(5)
                ->defaultItems(0)
                ->addActionLabel(__('Add passage'))
                ->reorderable()
                ->columnSpanFull()
                ->visible(function (Get $get) use ($bibleVersesEnabled): bool {
                    if (! $bibleVersesEnabled) {
                        return false;
                    }

                    return static::templateUsage($get, 'bible')->isUsed();
                })
                ->markAsRequired(function (Get $get) use ($bibleVersesEnabled): bool {
                    if (! $bibleVersesEnabled) {
                        return false;
                    }

                    return static::templateUsage($get, 'bible')->isRequired();
                }),
            TextInput::make('duration')
                ->label(__('Duration'))
                ->numeric()
                ->minValue(1)
                ->suffix(__('minutes'))
                ->required(fn (Get $get): bool => ! static::templateIsGlobal($get))
                ->visible(fn (Get $get): bool => ! static::templateIsGlobal($get))
                ->helperText(__('Changing duration shifts start times of this and following timed tasks.')),
            Toggle::make('before_start')
                ->label(__('Before the start time'))
                ->inline()
                ->extraFieldWrapperAttributes(['class' => 'wp-task-option'])
                ->helperText(__('Done before the service starts. These tasks are timed backwards from the start time.'))
                ->default(false)
                ->visible(fn (Get $get): bool => ! static::templateIsGlobal($get)),
            Select::make('assigned_user_id')
                ->label(__('Person'))
                ->options(function (Get $get) use ($event): array {
                    $templateId = filled($get('task_template_id'))
                        ? (int) $get('task_template_id')
                        : null;
                    $keepUserId = filled($get('assigned_user_id'))
                        ? (int) $get('assigned_user_id')
                        : null;

                    return app(UserAbilities::class)->assignablePersonOptions(
                        $event->church,
                        $templateId,
                        $keepUserId,
                    );
                })
                ->allowHtml()
                ->searchable()
                ->preload()
                ->nullable()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('accepted_verbally', false))
                ->visible(fn (Get $get): bool => static::templateUsage($get, 'person')->isUsed())
                ->markAsRequired(fn (Get $get): bool => static::templateUsage($get, 'person')->isRequired())
                ->helperText(function (?int $state, Get $get) use ($event, $abilitiesEnabled): ?HtmlString {
                    if ($abilitiesEnabled && blank($get('task_template_id'))) {
                        return new HtmlString(e(__('Select a task template first to see eligible people.')));
                    }

                    if (! $state) {
                        return null;
                    }

                    $user = User::query()->find($state);

                    if (! $user) {
                        return null;
                    }

                    $service = app(UnavailabilityConflictService::class);

                    if (! $service->userIsUnavailable($user, $event->starts_at, $event->endsAt(), $event->church)) {
                        return null;
                    }

                    $ranges = $service->forUser($user, $event->starts_at, $event->endsAt())
                        ->map(fn ($u) => $u->label())
                        ->implode('; ');

                    return new HtmlString(
                        '<strong class="text-danger-600 dark:text-danger-400">'
                        .e(__(':name is unavailable during this service (:ranges).', ['name' => $user->displayName(), 'ranges' => $ranges]))
                        .'</strong>'
                    );
                }),
            Select::make('assigned_team_id')
                ->label(__('Team'))
                ->options(function (Get $get) use ($event): array {
                    $template = static::resolveTemplate($get);
                    $query = Team::query()
                        ->where('church_id', $event->church_id)
                        ->orderBy('name');

                    if ($template?->team_type_id) {
                        $query->where('team_type_id', $template->team_type_id);
                    }

                    return $query->pluck('name', 'id')->all();
                })
                ->searchable()
                ->preload()
                ->nullable()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('accepted_verbally', false))
                ->visible(function (Get $get) use ($teamsEnabled): bool {
                    if (! $teamsEnabled) {
                        return false;
                    }

                    return static::templateUsage($get, 'team')->isUsed();
                })
                ->markAsRequired(function (Get $get) use ($teamsEnabled): bool {
                    if (! $teamsEnabled) {
                        return false;
                    }

                    return static::templateUsage($get, 'team')->isRequired();
                })
                ->helperText(function (Get $get): string {
                    $typeName = static::resolveTemplate($get)?->teamType?->name;

                    return $typeName
                        ? __('Only “:type” teams. Messages to a team go to its leader(s).', ['type' => $typeName])
                        : __('Service messages sent to this team go to its leader(s).');
                }),
            Toggle::make('accepted_verbally')
                ->label(__('Accepted verbally'))
                ->inline()
                ->extraFieldWrapperAttributes(['class' => 'wp-task-option'])
                ->helperText(__('They said yes in person or by phone. This overrides any reply to messages, and is cleared if the person or team changes.'))
                ->visible(fn (Get $get): bool => filled($get('assigned_user_id')) || filled($get('assigned_team_id'))),
            TextInput::make('sort_order')
                ->label(__('Sort order'))
                ->numeric()
                ->default(0)
                ->required()
                ->visible(fn (Get $get): bool => ! static::templateIsGlobal($get))
                ->helperText(__('Position on the timed timeline.')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFromRecord(EventTask $task): array
    {
        $data = $task->attributesToArray();
        $data['picture_ids'] = $task->pictures()->pluck('pictures.id')->all();
        $data['shared_file_ids'] = $task->sharedFiles()->pluck('shared_files.id')->all();
        $data['bible_passages'] = static::expandBiblePassagesForForm($task->bible_passages ?? []);
        $data['accepted_verbally'] = $task->isAcceptedVerbally();
        $data['announcement_points'] = AnnouncementPoints::fromDescription($task->description) ?: [['text' => '']];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data, Event $event): array
    {
        $template = isset($data['task_template_id'])
            ? TaskTemplate::query()->find($data['task_template_id'])
            : null;

        if ($template === null) {
            unset($data['announcement_points']);

            return $data;
        }

        if (AnnouncementPoints::appliesTo($data['name'] ?? null, $template->name) && array_key_exists('announcement_points', $data)) {
            $data['description'] = AnnouncementPoints::toDescription($data['announcement_points'] ?? []);
        }

        unset($data['announcement_points']);

        if (! $template->usage('person')->isUsed()) {
            $data['assigned_user_id'] = null;
        }

        if (! $template->usage('team')->isUsed()) {
            $data['assigned_team_id'] = null;
        } elseif (! empty($data['assigned_team_id']) && $template->team_type_id) {
            $matchesType = Team::query()
                ->whereKey($data['assigned_team_id'])
                ->where('team_type_id', $template->team_type_id)
                ->exists();

            if (! $matchesType) {
                $data['assigned_team_id'] = null;
            }
        }

        if (! $template->usage('description')->isUsed()) {
            $data['description'] = null;
        }

        if (! $template->usage('song')->isUsed()) {
            $data['song'] = null;
            $data['song_id'] = null;
        } else {
            $data = static::normalizeSongFields($data, $template, $event);
        }

        if (! $template->usage('picture')->isUsed()) {
            $data['picture_ids'] = [];
        }

        if (! $template->usage('file')->isUsed()) {
            $data['shared_file_ids'] = [];
        }

        if (! $template->usage('special_needs')->isUsed()) {
            $data['special_needs'] = null;
        }

        if (! $template->usage('bible')->isUsed()) {
            $data['bible_passages'] = [];
        } else {
            $data['bible_passages'] = static::collapseBiblePassages($data['bible_passages'] ?? []);
        }

        if ($template->use_globally) {
            $data['starts_at'] = null;
            $data['ends_at'] = null;
            $data['duration'] = null;
            $data['sort_order'] = $template->default_global_order;
            $data['before_start'] = false;
        } else {
            $data['duration'] = max(1, (int) ($data['duration'] ?? $template->default_duration ?? 5));
            // Timeline service owns start/end.
            unset($data['starts_at'], $data['ends_at']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function persist(EventTask $task, array $data, Event $event): EventTask
    {
        $data = static::normalize($data, $event);
        $pictureIds = array_map('intval', $data['picture_ids'] ?? []);
        $sharedFileIds = array_map('intval', $data['shared_file_ids'] ?? []);
        $acceptedVerbally = (bool) ($data['accepted_verbally'] ?? false);
        unset($data['picture_ids'], $data['shared_file_ids'], $data['expected_updated_at'], $data['accepted_verbally']);

        $material = app(MaterialAssignmentChange::class);
        $before = $material->snapshot($event->loadMissing(['tasks.template', 'tasks.responses']));

        $task->fill($data);
        static::applyVerbalAcceptance($task, $acceptedVerbally);
        $task->save();
        static::syncPictures($task, $pictureIds);
        static::syncSharedFiles($task, $sharedFileIds);
        app(EventTaskTimeline::class)->recalculate($event->fresh(['tasks.template']));
        $material->applyAfterRecalculate($event->fresh(['tasks.template', 'tasks.responses', 'tasks.event']), $before);

        return $task->fresh(['assignedUser', 'assignedTeam', 'template', 'pictures', 'sharedFiles', 'responses']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function create(Event $event, array $data): EventTask
    {
        if (blank($data['name'] ?? null) && ! empty($data['task_template_id'])) {
            $data['name'] = TaskTemplate::query()->find($data['task_template_id'])?->name;
        }

        $data = static::normalize($data, $event);
        $pictureIds = array_map('intval', $data['picture_ids'] ?? []);
        $sharedFileIds = array_map('intval', $data['shared_file_ids'] ?? []);
        $acceptedVerbally = (bool) ($data['accepted_verbally'] ?? false);
        unset($data['picture_ids'], $data['shared_file_ids'], $data['accepted_verbally']);

        $data['event_id'] = $event->id;

        $material = app(MaterialAssignmentChange::class);
        $before = $material->snapshot($event->loadMissing(['tasks.template', 'tasks.responses']));

        $task = new EventTask($data);
        static::applyVerbalAcceptance($task, $acceptedVerbally);
        $task->save();
        static::syncPictures($task, $pictureIds);
        static::syncSharedFiles($task, $sharedFileIds);
        app(EventTaskTimeline::class)->recalculate($event->fresh(['tasks.template']));
        $material->applyAfterRecalculate($event->fresh(['tasks.template', 'tasks.responses', 'tasks.event']), $before);

        return $task->fresh(['assignedUser', 'assignedTeam', 'template', 'pictures', 'sharedFiles', 'responses']);
    }

    /**
     * Keeps an existing verbal acceptance (and who recorded it) unless the assignee changed.
     */
    protected static function applyVerbalAcceptance(EventTask $task, bool $acceptedVerbally): void
    {
        $hasAssignee = filled($task->assigned_user_id) || filled($task->assigned_team_id);
        $reassigned = $task->exists && ($task->isDirty('assigned_user_id') || $task->isDirty('assigned_team_id'));

        if (! $acceptedVerbally || ! $hasAssignee) {
            $task->accepted_verbally_at = null;
            $task->accepted_verbally_by_user_id = null;

            return;
        }

        if ($task->accepted_verbally_at === null || $reassigned) {
            $task->accepted_verbally_at = now();
            $task->accepted_verbally_by_user_id = auth()->id();
        }
    }

    /**
     * @param  list<int>  $pictureIds
     */
    public static function syncPictures(EventTask $task, array $pictureIds): void
    {
        $template = $task->template ?? TaskTemplate::query()->find($task->task_template_id);

        if ($template === null || ! $template->usage('picture')->isUsed()) {
            $task->pictures()->detach();

            return;
        }

        $sync = [];
        foreach (array_values($pictureIds) as $index => $id) {
            $sync[$id] = ['sort_order' => $index];
        }

        $task->pictures()->sync($sync);
    }

    /**
     * @param  list<int>  $sharedFileIds
     */
    public static function syncSharedFiles(EventTask $task, array $sharedFileIds): void
    {
        $template = $task->template ?? TaskTemplate::query()->find($task->task_template_id);

        if ($template === null || ! $template->usage('file')->isUsed()) {
            $task->sharedFiles()->detach();

            return;
        }

        $sync = [];
        foreach (array_values($sharedFileIds) as $index => $id) {
            $sync[$id] = ['sort_order' => $index];
        }

        $task->sharedFiles()->sync($sync);
    }

    public static function biblePassagePreview(Get $get, Event $event): string
    {
        $bible = (string) ($get('bible') ?? '');
        $book = (string) ($get('book') ?? '');
        $chapter = $get('chapter');
        $verseStart = $get('verse_start');

        if ($bible === '' || $book === '' || blank($chapter) || blank($verseStart)) {
            // Already-collapsed row (view without expand) — use stored ref.
            $ref = (string) ($get('ref') ?? '');

            if ($bible !== '' && $ref !== '') {
                return app(ZamarBibleClient::class)->passagePreview($bible, $ref, $event->church);
            }

            return __('Select translation, book, chapter, and verse to preview the text.');
        }

        $verseEnd = filled($get('verse_end')) ? (int) $get('verse_end') : null;

        try {
            $ref = PassageRef::build($book, (int) $chapter, (int) $verseStart, $verseEnd);
        } catch (\Throwable) {
            return __('Invalid passage selection.');
        }

        return app(ZamarBibleClient::class)->passagePreview($bible, $ref, $event->church);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{bible: string, ref: string, label: string}>
     */
    public static function collapseBiblePassages(array $rows): array
    {
        $passages = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            // Already collapsed (edit without touching).
            if (filled($row['ref'] ?? null) && blank($row['book'] ?? null)) {
                $passages[] = [
                    'bible' => (string) ($row['bible'] ?? 'kjv'),
                    'ref' => (string) $row['ref'],
                    'label' => (string) ($row['label'] ?? $row['ref']),
                ];

                continue;
            }

            if (blank($row['book'] ?? null) || blank($row['chapter'] ?? null) || blank($row['verse_start'] ?? null)) {
                continue;
            }

            $verseEnd = filled($row['verse_end'] ?? null) ? (int) $row['verse_end'] : null;
            $ref = PassageRef::build(
                (string) $row['book'],
                (int) $row['chapter'],
                (int) $row['verse_start'],
                $verseEnd,
            );

            $passages[] = [
                'bible' => (string) ($row['bible'] ?? 'kjv'),
                'ref' => $ref,
                'label' => PassageRef::label(
                    (string) $row['book'],
                    (int) $row['chapter'],
                    (int) $row['verse_start'],
                    $verseEnd,
                ),
            ];
        }

        return array_values($passages);
    }

    /**
     * @param  list<array<string, mixed>>  $passages
     * @return list<array<string, mixed>>
     */
    public static function expandBiblePassagesForForm(array $passages): array
    {
        $rows = [];

        foreach ($passages as $passage) {
            if (! is_array($passage) || blank($passage['ref'] ?? null)) {
                continue;
            }

            $parsed = PassageRef::parse((string) $passage['ref']);

            if ($parsed === null) {
                $rows[] = [
                    'bible' => (string) ($passage['bible'] ?? 'kjv'),
                    'ref' => (string) $passage['ref'],
                    'label' => (string) ($passage['label'] ?? $passage['ref']),
                ];

                continue;
            }

            $rows[] = [
                'bible' => (string) ($passage['bible'] ?? 'kjv'),
                'book' => $parsed['book'],
                'chapter' => $parsed['chapter'],
                'verse_start' => $parsed['verse_start'],
                'verse_end' => $parsed['verse_end'],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function normalizeSongFields(array $data, TaskTemplate $template, Event $event): array
    {
        $songListEnabled = static::moduleEnabled($event, 'song_list');

        $songId = isset($data['song_id']) && filled($data['song_id'])
            ? (int) $data['song_id']
            : null;

        if ($songListEnabled && $songId !== null) {
            $song = Song::query()->find($songId);

            if ($song === null) {
                $data['song_id'] = null;
            } else {
                $data['song_id'] = $song->id;
                $data['song'] = $song->name;

                return $data;
            }
        }

        if (! $songListEnabled) {
            $data['song_id'] = null;
            $manual = trim((string) ($data['song'] ?? ''));
            $data['song'] = $manual === '' ? null : $manual;

            return $data;
        }

        $data['song_id'] = null;
        $manual = trim((string) ($data['song'] ?? ''));

        if ($manual === '') {
            $data['song'] = null;
        } elseif (! $template->allow_manual_song_entry) {
            $data['song'] = null;
        } else {
            $data['song'] = $manual;
        }

        return $data;
    }

    protected static function templateIsGlobal(Get $get): bool
    {
        return (bool) (static::resolveTemplate($get)?->use_globally ?? false);
    }

    protected static function isAnnouncement(Get $get): bool
    {
        return AnnouncementPoints::appliesTo($get('name'), static::resolveTemplate($get)?->name);
    }

    protected static function templateUsage(Get $get, string $field): FieldUsage
    {
        return static::resolveTemplate($get)?->usage($field) ?? FieldUsage::NotUsed;
    }

    protected static function templateAllowsManualSong(Get $get): bool
    {
        return (bool) (static::resolveTemplate($get)?->allow_manual_song_entry ?? false);
    }

    /**
     * Catalog-only asterisk: required song, song list on, manual entry off.
     */
    protected static function songCatalogRequired(Get $get, bool $songListEnabled): bool
    {
        if (! $songListEnabled || ! static::templateUsage($get, 'song')->isRequired()) {
            return false;
        }

        return ! static::templateAllowsManualSong($get);
    }

    /**
     * Manual asterisk: required song and no catalog pick available/selected.
     */
    protected static function songManualRequired(Get $get, bool $songListEnabled): bool
    {
        if (! static::templateUsage($get, 'song')->isRequired()) {
            return false;
        }

        if (! $songListEnabled) {
            return true;
        }

        if (! static::templateAllowsManualSong($get)) {
            return false;
        }

        return blank($get('song_id'));
    }

    protected static function resolveTemplate(Get $get): ?TaskTemplate
    {
        $templateId = $get('task_template_id');

        if (! $templateId) {
            return null;
        }

        return TaskTemplate::query()->with('teamType')->find($templateId);
    }

    protected static function moduleEnabled(Event $event, string $key): bool
    {
        $church = $event->church;

        if ($church === null) {
            return false;
        }

        return app(ChurchModuleManager::class)->isEnabled($church, $key);
    }
}
