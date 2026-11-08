<?php

namespace App\Services\ChurchTransfer;

/**
 * Every table that holds a church's data, in the order they can be inserted, and how each row points
 * at other rows. Export and import both read this map, and a test fails when a table is added to the
 * database without being listed here or in NOT_EXPORTED.
 */
class ChurchTables
{
    /**
     * scope: 'church' (the churches row), 'church_id' (rows with that column), or [column, table] for
     * rows that belong to an exported row of another table.
     * refs: column => table whose new id replaces the old one. 'permissions' and 'languages' are
     * shared by all churches and are matched by name instead of id.
     * files: columns holding a path on the public disk; the file travels with the row.
     * Ids inside JSON (module settings, spreadsheet preferences, message meta) are remapped by ChurchImporter.
     *
     * @var array<string, array{scope: string|array{0: string, 1: string}, refs?: array<string, string>, files?: list<string>}>
     */
    public const TABLES = [
        'churches' => ['scope' => 'church', 'files' => ['logo_path']],
        'users' => ['scope' => 'church_id', 'refs' => ['church_id' => 'churches', 'language_id' => 'languages'], 'files' => ['avatar_path']],
        'roles' => ['scope' => 'church_id', 'refs' => ['church_id' => 'churches']],
        'role_has_permissions' => ['scope' => ['role_id', 'roles'], 'refs' => ['role_id' => 'roles', 'permission_id' => 'permissions']],
        'model_has_roles' => ['scope' => 'church_id', 'refs' => ['church_id' => 'churches', 'role_id' => 'roles', 'model_id' => 'users']],
        'model_has_permissions' => ['scope' => 'church_id', 'refs' => ['church_id' => 'churches', 'permission_id' => 'permissions', 'model_id' => 'users']],
        'team_types' => ['scope' => 'church_id', 'refs' => ['church_id' => 'churches']],
        'teams' => ['scope' => 'church_id', 'refs' => ['church_id' => 'churches', 'team_type_id' => 'team_types']],
        'team_user' => ['scope' => ['team_id', 'teams'], 'refs' => ['team_id' => 'teams', 'user_id' => 'users']],
        'task_templates' => ['scope' => 'church_id', 'refs' => ['church_id' => 'churches', 'team_type_id' => 'team_types']],
        'task_template_user' => ['scope' => ['task_template_id', 'task_templates'], 'refs' => ['task_template_id' => 'task_templates', 'user_id' => 'users']],
        'songs' => ['scope' => 'church_id', 'refs' => ['church_id' => 'churches']],
        'pictures' => ['scope' => 'church_id', 'refs' => ['church_id' => 'churches'], 'files' => ['path']],
        'shared_files' => ['scope' => 'church_id', 'refs' => ['church_id' => 'churches', 'uploaded_by_user_id' => 'users'], 'files' => ['path']],
        'shared_file_team' => ['scope' => ['shared_file_id', 'shared_files'], 'refs' => ['shared_file_id' => 'shared_files', 'team_id' => 'teams']],
        'message_templates' => ['scope' => 'church_id', 'refs' => ['church_id' => 'churches']],
        // After message_templates: module settings point at templates (keys ending in _template_id).
        'church_modules' => ['scope' => 'church_id', 'refs' => ['church_id' => 'churches']],
        'events' => ['scope' => 'church_id', 'refs' => ['church_id' => 'churches']],
        'event_tasks' => ['scope' => ['event_id', 'events'], 'refs' => [
            'event_id' => 'events',
            'task_template_id' => 'task_templates',
            'assigned_user_id' => 'users',
            'assigned_team_id' => 'teams',
            'song_id' => 'songs',
            'accepted_verbally_by_user_id' => 'users',
        ]],
        'event_task_picture' => ['scope' => ['event_task_id', 'event_tasks'], 'refs' => ['event_task_id' => 'event_tasks', 'picture_id' => 'pictures']],
        'event_task_shared_file' => ['scope' => ['event_task_id', 'event_tasks'], 'refs' => ['event_task_id' => 'event_tasks', 'shared_file_id' => 'shared_files']],
        'event_task_responses' => ['scope' => 'church_id', 'refs' => [
            'church_id' => 'churches',
            'event_task_id' => 'event_tasks',
            'assignee_user_id' => 'users',
            'assignee_team_id' => 'teams',
            'responder_user_id' => 'users',
            'recorded_by_user_id' => 'users',
        ]],
        'unavailabilities' => ['scope' => 'church_id', 'refs' => ['church_id' => 'churches', 'user_id' => 'users', 'team_id' => 'teams']],
        'event_messages' => ['scope' => 'church_id', 'refs' => [
            'church_id' => 'churches',
            'event_id' => 'events',
            'event_task_id' => 'event_tasks',
            'created_by_user_id' => 'users',
            'message_template_id' => 'message_templates',
        ]],
        'event_message_user' => ['scope' => ['event_message_id', 'event_messages'], 'refs' => ['event_message_id' => 'event_messages', 'user_id' => 'users']],
        'outbound_messages' => ['scope' => 'church_id', 'refs' => [
            'church_id' => 'churches',
            'user_id' => 'users',
            'event_id' => 'events',
            'event_message_id' => 'event_messages',
        ]],
        'automatic_messages' => ['scope' => 'church_id', 'refs' => [
            'church_id' => 'churches',
            'message_template_id' => 'message_templates',
            'created_by_user_id' => 'users',
        ]],
        'automatic_message_deliveries' => ['scope' => ['automatic_message_id', 'automatic_messages'], 'refs' => [
            'automatic_message_id' => 'automatic_messages',
            'event_id' => 'events',
        ]],
    ];

    /**
     * Tables deliberately left out: shared by all churches, or short-lived and tied to one server.
     *
     * @var list<string>
     */
    public const NOT_EXPORTED = [
        'languages',
        'permissions',
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',
        'notifications',
        'assignment_response_tokens',
    ];

    /**
     * Encrypted module settings, as module key => dotted path inside the settings JSON.
     *
     * @var array<string, list<string>>
     */
    public const SECRETS = [
        'messaging' => ['email.password'],
        'messaging_sms' => ['api_token'],
        'messaging_whatsapp' => ['access_token'],
        'messaging_ntfy' => ['access_token'],
        'messaging_telegram' => ['bot_token', 'webhook_secret'],
        'bible_verses' => ['api_key'],
    ];

    /** Settings that are stored encrypted (the Telegram webhook secret is stored as plain text). */
    public const ENCRYPTED_SECRETS = [
        'messaging' => ['email.password'],
        'messaging_sms' => ['api_token'],
        'messaging_whatsapp' => ['access_token'],
        'messaging_ntfy' => ['access_token'],
        'messaging_telegram' => ['bot_token'],
        'bible_verses' => ['api_key'],
    ];

    /**
     * Uploaded file paths are relative paths on the public disk; anything else is never read or written.
     */
    public static function isSafeFilePath(mixed $path): bool
    {
        return is_string($path) && $path !== '' && ! str_contains($path, '..') && ! str_starts_with($path, '/') && ! str_contains($path, '\\');
    }
}
