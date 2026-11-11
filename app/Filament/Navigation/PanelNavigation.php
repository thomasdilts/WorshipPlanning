<?php

namespace App\Filament\Navigation;

use App\Filament\Pages\BibleSettings;
use App\Filament\Pages\ChurchSettings;
use App\Filament\Pages\ChurchStarter;
use App\Filament\Pages\ExportChurchData;
use App\Filament\Pages\ManageModules;
use App\Filament\Pages\MessageHistory;
use App\Filament\Pages\MessagingSettings;
use App\Filament\Pages\MyMessages;
use App\Filament\Pages\MyTasks;
use App\Filament\Pages\MyUnavailability;
use App\Filament\Pages\NtfyMessagingSettings;
use App\Filament\Pages\SmsMessagingSettings;
use App\Filament\Pages\SongStatistics;
use App\Filament\Pages\TelegramMessagingSettings;
use App\Filament\Pages\WhatsAppMessagingSettings;
use App\Filament\Resources\AutomaticMessages\AutomaticMessageResource;
use App\Filament\Resources\Churches\ChurchResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ListServiceTemplates;
use App\Filament\Resources\Events\Pages\TaskSpreadsheet;
use App\Filament\Resources\Languages\LanguageResource;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Resources\Pictures\PictureResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\SharedFiles\SharedFileResource;
use App\Filament\Resources\Songs\SongResource;
use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\TeamTypes\TeamTypeResource;
use App\Filament\Resources\Unavailabilities\UnavailabilityResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;

use function Filament\Support\original_request;

/**
 * The single definition of the panel's primary navigation.
 *
 * Visibility comes from each destination's own canAccess(), which already encodes permission,
 * team membership and module state. A group with one visible destination collapses into a plain item.
 */
class PanelNavigation
{
    public function __construct(private PanelAudience $audience) {}

    public function build(NavigationBuilder $builder): NavigationBuilder
    {
        $builder->items($this->primaryItems());

        $groups = [
            $this->group(__('People & teams'), [
                $this->forClass(TeamResource::class, __('Teams')),
                $this->forClass(UserResource::class, __('People')),
                $this->forClass(UnavailabilityResource::class, __('Unavailabilities'), Heroicon::OutlinedCalendarDays),
                $this->forClass(TeamTypeResource::class, __('Team types')),
                $this->forClass(MessageHistory::class, __('Message history')),
            ]),
            $this->group(__('Library'), [
                $this->forClass(SongResource::class, __('Songs')),
                $this->forClass(SharedFileResource::class, __('Files')),
                $this->forClass(PictureResource::class, __('Pictures')),
                $this->forClass(SongStatistics::class, __('Song statistics')),
            ]),
            $this->group(__('Settings'), [
                $this->forClass(ChurchStarter::class, __('Getting started'), Heroicon::OutlinedRocketLaunch)
                    ->visible(fn (): bool => ChurchStarter::shouldRegisterNavigation()),
                $this->forClass(ChurchSettings::class, __('Church')),
                $this->forClass(RoleResource::class, __('Roles')),
                NavigationItem::make(__('Service templates'))
                    ->icon(Heroicon::OutlinedBookmark)
                    ->url(fn (): string => EventResource::getUrl('templates'))
                    ->isActiveWhen(fn (): bool => original_request()->routeIs(ListServiceTemplates::getRouteName()))
                    ->visible(fn (): bool => ListServiceTemplates::canAccess()),
                $this->forClass(TaskTemplateResource::class, __('Activity templates')),
                $this->forClass(MessageTemplateResource::class, __('Message templates')),
                $this->forClass(AutomaticMessageResource::class, __('Automatic messages')),
                $this->forClass(LanguageResource::class),
                $this->forClass(ExportChurchData::class, __('Export church data')),
            ]),
            $this->group(__('Modules'), [
                $this->forClass(ManageModules::class, __('Module selection')),
                $this->forClass(MessagingSettings::class),
                $this->forClass(SmsMessagingSettings::class),
                $this->forClass(NtfyMessagingSettings::class),
                $this->forClass(WhatsAppMessagingSettings::class),
                $this->forClass(TelegramMessagingSettings::class),
                $this->forClass(BibleSettings::class, __('Bible settings')),
            ], collapsedLabel: __('Modules')),
        ];

        foreach ($groups as ['group' => $group, 'collapsedLabel' => $collapsedLabel]) {
            $visible = array_values(array_filter($group->getItems(), fn (NavigationItem $item): bool => $item->isVisible()));

            if (count($visible) === 1 && $visible[0]->getLabel() !== __('Service templates')) {
                $item = $visible[0]->group(null);
                $builder->item($collapsedLabel === null ? $item : $item->label($collapsedLabel));

                continue;
            }

            $builder->group($group);
        }

        return $builder;
    }

    /**
     * @return list<NavigationItem>
     */
    private function primaryItems(): array
    {
        $tasksByEvent = NavigationItem::make(__('Tasks by event'))
            ->icon(Heroicon::OutlinedTableCells)
            ->url(fn (): string => EventResource::getUrl('task-spreadsheet'))
            ->isActiveWhen(fn (): bool => original_request()->routeIs(TaskSpreadsheet::getRouteName()))
            ->visible(fn (): bool => TaskSpreadsheet::canAccess());
        $services = $this->forClass(EventResource::class)
            ->isActiveWhen(fn (): bool => original_request()->routeIs(EventResource::getRouteBaseName().'.*')
                && ! original_request()->routeIs(TaskSpreadsheet::getRouteName())
                && ! original_request()->routeIs(ListServiceTemplates::getRouteName()));
        $mySchedule = $this->forClass(MyTasks::class, __('My schedule'), Heroicon::OutlinedClipboardDocumentCheck);
        $availability = $this->forClass(MyUnavailability::class, __('Availability'), Heroicon::OutlinedCalendarDateRange);
        $myMessages = $this->forClass(MyMessages::class, __('My messages'), Heroicon::OutlinedInbox);
        $churches = $this->forClass(ChurchResource::class);

        return $this->audience->isPlanner()
            ? [$tasksByEvent, $services, $mySchedule, $availability, $myMessages, $churches]
            : [$mySchedule, $availability, $services, $tasksByEvent, $myMessages, $churches];
    }

    /**
     * The few destinations shown in the phone tab bar: the first top-level items for this user.
     *
     * @return list<NavigationItem>
     */
    public function mobileTabs(int $limit = 3): array
    {
        foreach ($this->build(new NavigationBuilder)->getNavigation() as $group) {
            if ($group->getLabel() !== null) {
                continue;
            }

            return array_slice(
                array_values(array_filter($group->getItems(), fn (NavigationItem $item): bool => $item->isVisible())),
                0,
                $limit,
            );
        }

        return [];
    }

    /**
     * @param  class-string  $class
     */
    private function forClass(string $class, ?string $label = null, mixed $icon = null): NavigationItem
    {
        /** @var NavigationItem $item */
        $item = $class::getNavigationItems()[0];

        $item->group(null)->sort(null)->visible(fn (): bool => $class::canAccess());

        if ($label !== null) {
            $item->label($label);
        }

        if ($icon !== null) {
            $item->icon($icon);
        }

        return $item;
    }

    /**
     * @param  list<NavigationItem>  $items
     * @param  string|null  $collapsedLabel  Label for the plain item the group becomes when only one destination is visible.
     * @return array{group: NavigationGroup, collapsedLabel: string|null}
     */
    private function group(string $label, array $items, ?string $collapsedLabel = null): array
    {
        return ['group' => NavigationGroup::make($label)->items($items), 'collapsedLabel' => $collapsedLabel];
    }
}
