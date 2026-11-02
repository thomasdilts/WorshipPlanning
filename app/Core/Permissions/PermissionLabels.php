<?php

namespace App\Core\Permissions;

use Illuminate\Support\Str;

final class PermissionLabels
{
    /**
     * @return array<string, string> permission name => label
     */
    public static function optionsForModule(string $moduleKey): array
    {
        $permissions = app(PermissionRegistry::class)->forModule($moduleKey);
        $options = [];

        foreach ($permissions as $permission) {
            $options[$permission] = self::label($permission);
        }

        return $options;
    }

    /**
     * @return array<string, array<string, string>> module => [permission => label]
     */
    public static function groupedOptions(): array
    {
        $grouped = [];

        foreach (app(PermissionRegistry::class)->allByModule() as $module => $permissions) {
            $grouped[$module] = [];
            foreach ($permissions as $permission) {
                $grouped[$module][$permission] = self::label($permission);
            }
        }

        return $grouped;
    }

    public static function label(string $permission): string
    {
        return match ($permission) {
            'church.view' => __('View church settings'),
            'church.update' => __('Edit church settings'),
            'users.view' => __('View people'),
            'users.create' => __('Create people'),
            'users.update' => __('Edit people'),
            'users.delete' => __('Delete people'),
            'roles.view' => __('View roles'),
            'roles.manage' => __('Manage roles & permissions'),
            'modules.manage' => __('Enable/disable modules'),
            'events.view' => __('View events'),
            'events.create' => __('Create events'),
            'events.update' => __('Edit events'),
            'events.delete' => __('Delete events'),
            'events.tasks_by_event' => __('View tasks by event'),
            'task_templates.view' => __('Use task templates'),
            'task_templates.manage' => __('Manage task templates'),
            'messaging.view' => __('View all messages'),
            'messaging.templates.manage' => __('Manage message templates'),
            'messaging.settings.manage' => __('Manage messaging / email settings'),
            'messaging.send' => __('Send messages'),
            'unavailability.view' => __('View unavailability'),
            'unavailability.manage_own' => __('Manage own unavailability'),
            'unavailability.manage' => __('Manage everyone’s unavailability'),
            'teams.view' => __('View all teams'),
            'teams.manage' => __('Create & edit teams'),
            'pictures.view' => __('View picture library'),
            'pictures.manage' => __('Manage picture library'),
            'file_sharing.view' => __('View shared files'),
            'file_sharing.manage' => __('Manage shared files'),
            'bible_verses.view' => __('View bible verses on tasks'),
            'bible_verses.manage' => __('Manage bible API settings'),
            'song_list.view' => __('View song list'),
            'song_list.manage' => __('Manage song list (import/export)'),
            'song_statistics.view' => __('View song usage statistics'),
            'abilities.view_own' => __('View own abilities'),
            'abilities.edit_own' => __('Edit own abilities'),
            'abilities.manage' => __('View/edit others’ abilities'),
            default => Str::of($permission)->replace(['.', '_'], ' ')->title()->toString(),
        };
    }

    public static function moduleHeading(string $moduleKey): string
    {
        return match ($moduleKey) {
            'core' => __('Church & access'),
            'events' => __('Events & planning'),
            'messaging' => __('Messaging'),
            'messaging_sms' => __('Messaging SMS'),
            'messaging_whatsapp' => __('Messaging WhatsApp'),
            'messaging_ntfy' => __('Messaging ntfy'),
            'messaging_telegram' => __('Messaging Telegram'),
            'unavailability' => __('Unavailability'),
            'teams' => __('Teams'),
            'pictures' => __('Pictures'),
            'file_sharing' => __('File sharing'),
            'bible_verses' => __('Bible verses'),
            'song_list' => __('Song list'),
            'song_statistics' => __('Song statistics'),
            'abilities' => __('Abilities'),
            default => Str::headline($moduleKey),
        };
    }
}
