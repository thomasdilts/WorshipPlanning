<?php

namespace App\Services\Demo;

use App\Core\Modules\ChurchModuleManager;
use Carbon\CarbonImmutable;
use Faker\Factory as FakerFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Messaging\Support\MessagingAvailability;
use RuntimeException;

/**
 * Turns one church into an anonymized, English demo snapshot.
 *
 * People get fake names, @example.com addresses and no phone, avatar or chat ids. Text is replaced
 * through a translation map keyed by a hash of the original, so the map in the repository never
 * holds the original wording. Free text without a translation is dropped rather than kept, because
 * it often names real people. Names (events, teams, task types) must all be translated.
 * Module settings are rebuilt from blank defaults, so no credentials leave the source database.
 */
class DemoSnapshotBuilder
{
    public const SNAPSHOT_VERSION = 1;

    /** Months of history kept before the anchor date; every later event is kept. */
    public const MONTHS_OF_HISTORY = 4;

    /** Demo logins, picked from people holding each role (most assigned tasks first). */
    public const LOGINS = [
        'admin@example.com' => ['role' => 'Church Admin', 'without' => []],
        'planner@example.com' => ['role' => 'Event Planner', 'without' => ['Church Admin']],
        'leader@example.com' => ['role' => 'Team Leader', 'without' => ['Church Admin', 'Event Planner']],
        'member@example.com' => ['role' => 'Member', 'without' => ['Church Admin', 'Event Planner', 'Team Leader']],
    ];

    /** @var array<string, string> hash => English */
    protected array $translations = [];

    /** @var array<string, string> hash => original, for text with no translation */
    protected array $missing = [];

    /** @var array<string, string> hash => original, for names with no translation (fatal) */
    protected array $missingNames = [];

    public function __construct(
        protected ChurchModuleManager $modules,
    ) {}

    public static function hashFor(string $text): string
    {
        return sha1(self::normalize($text));
    }

    public static function normalize(string $text): string
    {
        return trim(str_replace("\r\n", "\n", $text));
    }

    /**
     * @param  array<string, string>  $translations  hash => English
     * @param  list<array{name: string, author: string}>  $hymns
     * @return array{snapshot: array<string, mixed>, missing: array<string, string>, missing_names: array<string, string>}
     */
    public function build(int $churchId, CarbonImmutable $anchor, array $translations, array $hymns): array
    {
        $this->translations = $translations;
        $this->missing = [];
        $this->missingNames = [];

        $church = DB::table('churches')->where('id', $churchId)->first()
            ?? throw new RuntimeException("Church {$churchId} not found.");

        $historyStart = $anchor->subMonths(self::MONTHS_OF_HISTORY)->startOfDay();

        $events = DB::table('events')->where('church_id', $churchId)
            ->where('starts_at', '>=', $historyStart->toDateTimeString())
            ->orderBy('id')->get();
        $tasks = DB::table('event_tasks')->whereIn('event_id', $events->pluck('id'))->orderBy('id')->get();
        $users = DB::table('users')->where('church_id', $churchId)->orderBy('id')->get();
        $roles = DB::table('roles')->where('church_id', $churchId)->orderBy('id')->get();
        $userRoles = DB::table('model_has_roles')->where('church_id', $churchId)->get();
        $teams = DB::table('teams')->where('church_id', $churchId)->orderBy('id')->get();
        $templates = DB::table('task_templates')->where('church_id', $churchId)->orderBy('id')->get();
        $englishId = DB::table('languages')->where('iso', 'en')->value('id');

        [$songs, $songIdMap] = $this->songs($churchId, $tasks, $hymns);

        $tables = [
            'languages' => $this->rows(DB::table('languages')->orderBy('id')->get()),
            'permissions' => $this->rows(DB::table('permissions')->orderBy('id')->get()),
            'churches' => [[
                ...(array) $church,
                'name' => $this->translateName($church->name),
                'slug' => Str::slug($this->translateName($church->name)),
                'locale' => 'en',
                'logo_path' => null,
            ]],
            'church_modules' => $this->modules($churchId),
            'roles' => $this->rows($roles),
            'role_has_permissions' => $this->rows(DB::table('role_has_permissions')->whereIn('role_id', $roles->pluck('id'))->get()),
            'users' => $this->users($users, $userRoles, $roles, $tasks, $englishId),
            'model_has_roles' => $this->rows($userRoles),
            'model_has_permissions' => $this->rows(DB::table('model_has_permissions')->where('church_id', $churchId)->get()),
            'team_types' => $this->rows(DB::table('team_types')->where('church_id', $churchId)->orderBy('id')->get(), names: ['name']),
            'teams' => $this->rows($teams, names: ['name'], texts: ['description']),
            'team_user' => $this->rows(DB::table('team_user')->whereIn('team_id', $teams->pluck('id'))->get()),
            'task_templates' => $this->rows($templates, names: ['name'], texts: ['description']),
            'task_template_user' => $this->rows(DB::table('task_template_user')->whereIn('task_template_id', $templates->pluck('id'))->get()),
            'songs' => $songs,
            'events' => $this->rows($events, names: ['name'], texts: ['description']),
            'event_tasks' => array_map(fn (array $task): array => [
                ...$task,
                'song_id' => $task['song_id'] === null ? null : ($songIdMap[$task['song_id']] ?? null),
                'song' => null,
                'special_needs' => null,
                'legacy_person_text' => null,
                'legacy_team_text' => null,
                'person_text' => null,
                'team_text' => null,
            ], $this->rows($tasks, texts: ['name', 'description'])),
            'event_task_responses' => array_map(fn (array $response): array => [
                ...$response, 'note' => null, 'legacy_source' => null, 'legacy_id' => null,
            ], $this->rows(DB::table('event_task_responses')->whereIn('event_task_id', $tasks->pluck('id'))->orderBy('id')->get())),
            'unavailabilities' => array_map(fn (array $row): array => [...$row, 'note' => null], $this->rows(
                DB::table('unavailabilities')->where('church_id', $churchId)
                    ->where('ends_on', '>=', $historyStart->toDateString())->orderBy('id')->get(),
            )),
        ];

        return [
            'snapshot' => [
                'version' => self::SNAPSHOT_VERSION,
                'anchor_date' => $anchor->toDateString(),
                'church_id' => $churchId,
                'tables' => $tables,
            ],
            'missing' => $this->missing,
            'missing_names' => $this->missingNames,
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  list<string>  $names  columns that must be translated
     * @param  list<string>  $texts  columns that are dropped when there's no translation
     * @return list<array<string, mixed>>
     */
    protected function rows(Collection $rows, array $names = [], array $texts = []): array
    {
        return $rows->map(function (object $row) use ($names, $texts): array {
            $row = (array) $row;

            foreach ($names as $column) {
                $row[$column] = $this->translateName($row[$column]);
            }

            foreach ($texts as $column) {
                $row[$column] = $this->translateText($row[$column]);
            }

            return $row;
        })->values()->all();
    }

    protected function translateName(string $text): string
    {
        $hash = self::hashFor($text);

        if (isset($this->translations[$hash])) {
            return $this->translations[$hash];
        }

        $this->missingNames[$hash] = self::normalize($text);

        return $text;
    }

    protected function translateText(?string $text): ?string
    {
        if ($text === null || self::normalize($text) === '') {
            return null;
        }

        $hash = self::hashFor($text);

        if (isset($this->translations[$hash])) {
            return $this->translations[$hash];
        }

        $this->missing[$hash] = self::normalize($text);

        return null;
    }

    /**
     * @param  Collection<int, object>  $users
     * @param  Collection<int, object>  $userRoles
     * @param  Collection<int, object>  $roles
     * @param  Collection<int, object>  $tasks
     * @return list<array<string, mixed>>
     */
    protected function users(Collection $users, Collection $userRoles, Collection $roles, Collection $tasks, ?int $englishId): array
    {
        $roleNames = $roles->pluck('name', 'id');
        $rolesByUser = $userRoles->groupBy('model_id')
            ->map(fn (Collection $rows): array => $rows->map(fn (object $row): ?string => $roleNames[$row->role_id] ?? null)->filter()->values()->all());
        $taskCounts = $tasks->whereNotNull('assigned_user_id')->countBy('assigned_user_id');
        $loginEmails = $this->pickLogins($users, $rolesByUser->all(), $taskCounts->all());

        $faker = FakerFactory::create('en_US');
        $faker->seed(20261103);
        $usedEmails = array_flip(array_keys(self::LOGINS));

        return $users->map(function (object $user) use ($faker, &$usedEmails, $loginEmails, $englishId): array {
            $first = $faker->firstName();
            $last = $faker->lastName();
            $email = $loginEmails[$user->id] ?? null;

            if ($email === null) {
                $base = Str::lower(Str::ascii("{$first}.{$last}"));
                $email = "{$base}@example.com";

                for ($n = 2; isset($usedEmails[$email]); $n++) {
                    $email = "{$base}{$n}@example.com";
                }

                $usedEmails[$email] = true;
            }

            return [
                ...(array) $user,
                'name' => "{$first} {$last}",
                'display_name' => $first,
                'email' => $email,
                'phone' => null,
                'avatar_path' => null,
                'password' => '',
                'remember_token' => null,
                'is_platform_admin' => false,
                'message_preference' => 'ntfy',
                'ntfy_topic' => null,
                'telegram_chat_id' => null,
                'telegram_username' => null,
                'language_id' => $englishId,
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, object>  $users
     * @param  array<int, list<string>>  $rolesByUser
     * @param  array<int, int>  $taskCounts
     * @return array<int, string> user id => login email
     */
    protected function pickLogins(Collection $users, array $rolesByUser, array $taskCounts): array
    {
        $picked = [];

        foreach (self::LOGINS as $email => $rule) {
            $candidate = $users
                ->reject(fn (object $user): bool => isset($picked[$user->id]))
                ->filter(function (object $user) use ($rolesByUser, $rule): bool {
                    $userRoles = $rolesByUser[$user->id] ?? [];

                    return in_array($rule['role'], $userRoles, true) && array_intersect($rule['without'], $userRoles) === [];
                })
                ->sortByDesc(fn (object $user): int => $taskCounts[$user->id] ?? 0)
                ->first();

            if ($candidate !== null) {
                $picked[$candidate->id] = $email;
            }
        }

        return $picked;
    }

    /**
     * Replace the song catalog with public-domain hymns. Songs used in the kept events map to
     * hymns in order of use, so the most-sung song becomes the first hymn.
     *
     * @param  Collection<int, object>  $tasks
     * @param  list<array{name: string, author: string}>  $hymns
     * @return array{0: list<array<string, mixed>>, 1: array<int, int>}
     */
    protected function songs(int $churchId, Collection $tasks, array $hymns): array
    {
        $now = now()->toDateTimeString();
        $songs = [];

        foreach (array_values($hymns) as $index => $hymn) {
            $songs[] = [
                'id' => $index + 1,
                'church_id' => $churchId,
                'name' => $hymn['name'],
                'name2' => null,
                'author' => $hymn['author'],
                'description' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $usedSongIds = $tasks->whereNotNull('song_id')->countBy('song_id')->sortDesc()->keys();
        $map = [];

        foreach ($usedSongIds->values() as $index => $songId) {
            $map[(int) $songId] = ($index % max(count($songs), 1)) + 1;
        }

        return [$songs, $map];
    }

    /**
     * Non-messaging modules the church uses plus ntfy, all with blank default settings.
     *
     * @return list<array<string, mixed>>
     */
    protected function modules(int $churchId): array
    {
        $now = now()->toDateTimeString();
        $keys = DB::table('church_modules')->where('church_id', $churchId)->where('enabled', true)->pluck('module_key')
            ->reject(fn (string $key): bool => in_array($key, config('demo.locked_modules', []), true))
            ->push(MessagingAvailability::MODULE_NTFY)
            ->unique()->values();

        return $keys->map(fn (string $key, int $index): array => [
            'id' => $index + 1,
            'church_id' => $churchId,
            'module_key' => $key,
            'enabled' => true,
            'settings' => json_encode($this->modules->defaultSettings($key)),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();
    }
}
