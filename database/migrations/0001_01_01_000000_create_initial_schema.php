<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createFrameworkTables();
        $this->createChurchAndUserTables();
        $this->createPermissionTables();
        $this->createTeamTables();
        $this->createPlanningTables();
        $this->createMessagingTables();
        $this->createAutomaticMessageTables();
        $this->createMediaTables();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'automatic_message_deliveries',
            'automatic_messages',
            'assignment_response_tokens',
            'event_task_responses',
            'event_task_shared_file',
            'shared_file_team',
            'shared_files',
            'event_task_picture',
            'pictures',
            'outbound_messages',
            'event_message_user',
            'event_messages',
            'message_templates',
            'unavailabilities',
            'task_template_user',
            'event_tasks',
            'events',
            'task_templates',
            'songs',
            'team_user',
            'teams',
            'team_types',
            'church_modules',
            config('permission.table_names.role_has_permissions'),
            config('permission.table_names.model_has_roles'),
            config('permission.table_names.model_has_permissions'),
            config('permission.table_names.roles'),
            config('permission.table_names.permissions'),
            'notifications',
            'sessions',
            'password_reset_tokens',
            'users',
            'languages',
            'churches',
            'failed_jobs',
            'job_batches',
            'jobs',
            'cache_locks',
            'cache',
        ] as $tableName) {
            Schema::dropIfExists($tableName);
        }

        Schema::enableForeignKeyConstraints();
    }

    protected function createFrameworkTables(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();

            $table->index(['connection', 'queue', 'failed_at']);
        });
    }

    protected function createChurchAndUserTables(): void
    {
        Schema::create('churches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default('Europe/Stockholm');
            $table->string('locale')->default('en');
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('iso', 16)->unique();
            $table->string('name_en');
            $table->string('name_native');
            $table->string('font');
            $table->string('direction', 3);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->string('ntfy_topic', 64)->nullable();
            $table->string('telegram_chat_id', 32)->nullable();
            $table->string('telegram_username', 64)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('is_platform_admin')->default(false);
            $table->string('message_preference', 20)->default('email');
            $table->foreignId('language_id')->nullable()->constrained('languages')->nullOnDelete();
            $table->string('preferred_landing', 40)->nullable();
            $table->json('spreadsheet_preferences')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->unique(['church_id', 'email']);
            $table->unique('email');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('church_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->string('module_key');
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['church_id', 'module_key']);
        });
    }

    /**
     * Spatie laravel-permission tables, scoped per church through the configured team key.
     */
    protected function createPermissionTables(): void
    {
        $teams = config('permission.teams');
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';

        throw_if(empty($tableNames), 'Error: config/permission.php not loaded. Run [php artisan config:clear] and try again.');
        throw_if($teams && empty($columnNames['team_foreign_key'] ?? null), 'Error: team_foreign_key on config/permission.php not loaded. Run [php artisan config:clear] and try again.');

        Schema::create($tableNames['permissions'], static function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create($tableNames['roles'], static function (Blueprint $table) use ($teams, $columnNames) {
            $table->id();
            if ($teams || config('permission.testing')) {
                $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable();
                $table->index($columnNames['team_foreign_key'], 'roles_team_foreign_key_index');
            }
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            if ($teams || config('permission.testing')) {
                $table->unique([$columnNames['team_foreign_key'], 'name', 'guard_name']);
            } else {
                $table->unique(['name', 'guard_name']);
            }
        });

        Schema::create($tableNames['model_has_permissions'], static function (Blueprint $table) use ($tableNames, $columnNames, $pivotPermission, $teams) {
            $table->unsignedBigInteger($pivotPermission);

            $table->string('model_type');
            $table->unsignedBigInteger($columnNames['model_morph_key']);
            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign($pivotPermission)
                ->references('id')
                ->on($tableNames['permissions'])
                ->cascadeOnDelete();
            if ($teams) {
                $table->unsignedBigInteger($columnNames['team_foreign_key']);
                $table->index($columnNames['team_foreign_key'], 'model_has_permissions_team_foreign_key_index');

                $table->primary([$columnNames['team_foreign_key'], $pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_permissions_permission_model_type_primary');
            } else {
                $table->primary([$pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_permissions_permission_model_type_primary');
            }
        });

        Schema::create($tableNames['model_has_roles'], static function (Blueprint $table) use ($tableNames, $columnNames, $pivotRole, $teams) {
            $table->unsignedBigInteger($pivotRole);

            $table->string('model_type');
            $table->unsignedBigInteger($columnNames['model_morph_key']);
            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign($pivotRole)
                ->references('id')
                ->on($tableNames['roles'])
                ->cascadeOnDelete();
            if ($teams) {
                $table->unsignedBigInteger($columnNames['team_foreign_key']);
                $table->index($columnNames['team_foreign_key'], 'model_has_roles_team_foreign_key_index');

                $table->primary([$columnNames['team_foreign_key'], $pivotRole, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_roles_role_model_type_primary');
            } else {
                $table->primary([$pivotRole, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_roles_role_model_type_primary');
            }
        });

        Schema::create($tableNames['role_has_permissions'], static function (Blueprint $table) use ($tableNames, $pivotRole, $pivotPermission) {
            $table->unsignedBigInteger($pivotPermission);
            $table->unsignedBigInteger($pivotRole);

            $table->foreign($pivotPermission)
                ->references('id')
                ->on($tableNames['permissions'])
                ->cascadeOnDelete();

            $table->foreign($pivotRole)
                ->references('id')
                ->on($tableNames['roles'])
                ->cascadeOnDelete();

            $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
        });

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    protected function createTeamTables(): void
    {
        Schema::create('team_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['church_id', 'name']);
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_type_id')->constrained('team_types')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['church_id', 'name']);
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_leader')->default(false);
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
            $table->index(['user_id', 'is_leader']);
        });

        Schema::create('unavailabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['church_id', 'user_id']);
            $table->index(['church_id', 'team_id']);
            $table->index(['user_id', 'starts_on', 'ends_on']);
            $table->index(['team_id', 'starts_on', 'ends_on']);
        });
    }

    protected function createPlanningTables(): void
    {
        Schema::create('songs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name2')->nullable();
            $table->string('author')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['church_id', 'name']);
            $table->index(['church_id', 'author']);
        });

        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('default_order')->default(0);
            $table->unsignedInteger('default_duration')->default(5);
            $table->boolean('use_globally')->default(false);
            $table->unsignedInteger('default_global_order')->default(0);
            $table->string('person_usage', 20)->default('optional');
            $table->string('team_usage', 20)->default('not_used');
            $table->foreignId('team_type_id')->nullable()->constrained('team_types')->nullOnDelete();
            $table->string('description_usage', 20)->default('optional');
            $table->string('special_needs_usage', 20)->default('optional');
            $table->string('song_usage', 20)->default('not_used');
            $table->boolean('allow_manual_song_entry')->default(false);
            $table->string('picture_usage', 20)->default('not_used');
            $table->string('file_usage', 20)->default('not_used');
            $table->string('bible_usage', 20)->default('not_used');
            $table->boolean('is_active')->default(true);
            $table->boolean('show_in_spreadsheet')->default(false);
            $table->timestamps();

            $table->index(['church_id', 'is_active']);
        });

        Schema::create('task_template_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_template_id', 'user_id']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_template')->default(false);
            $table->dateTime('starts_at');
            $table->timestamp('shared_at')->nullable();
            $table->timestamp('plan_changed_at')->nullable();
            $table->timestamps();

            $table->index(['church_id', 'starts_at']);
            $table->index(['church_id', 'is_template']);
        });

        Schema::create('event_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_template_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->timestamp('accepted_verbally_at')->nullable();
            $table->foreignId('accepted_verbally_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('legacy_person_text')->nullable();
            $table->string('legacy_team_text')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('song')->nullable();
            $table->foreignId('song_id')->nullable()->constrained('songs')->nullOnDelete();
            $table->text('special_needs')->nullable();
            // List of {bible, ref, label} — verse text stays in the Zamar API.
            $table->json('bible_passages')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('before_start')->default(false);
            $table->timestamps();

            $table->index(['event_id', 'sort_order']);
        });

        Schema::create('event_task_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('responder_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20);
            $table->string('method', 20)->nullable();
            $table->dateTime('requested_at')->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->text('note')->nullable();
            $table->string('legacy_source', 40)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();

            $table->index(['event_task_id', 'occurred_at']);
            $table->unique(['church_id', 'legacy_source', 'legacy_id'], 'event_task_responses_legacy_unique');
        });

        Schema::create('assignment_response_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 16)->unique();
            $table->foreignId('event_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['event_task_id', 'user_id']);
        });
    }

    protected function createMessagingTables(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key')->nullable();
            $table->string('purpose', 20)->default('service');
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->text('text_body')->nullable();
            $table->string('whatsapp_template', 512)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['church_id', 'key']);
            $table->index(['church_id', 'is_active']);
        });

        Schema::create('event_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('message_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->text('text_body')->nullable();
            $table->text('custom_message')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'created_at']);
        });

        Schema::create('event_message_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_message_id')->constrained('event_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('delivery_status', 20)->default('pending');
            $table->text('delivery_error')->nullable();
            $table->timestamps();

            $table->unique(['event_message_id', 'user_id']);
        });

        Schema::create('outbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->string('template_key')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['church_id', 'created_at']);
        });
    }

    protected function createAutomaticMessageTables(): void
    {
        Schema::create('automatic_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('message_template_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('hours_before');
            $table->text('custom_message')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['church_id', 'is_active']);
        });

        Schema::create('automatic_message_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automatic_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamps();

            $table->unique(['automatic_message_id', 'event_id'], 'automatic_message_deliveries_unique');
        });
    }

    protected function createMediaTables(): void
    {
        Schema::create('pictures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('path');
            $table->timestamps();

            $table->index(['church_id', 'name']);
        });

        Schema::create('event_task_picture', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('picture_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['event_task_id', 'picture_id']);
        });

        Schema::create('shared_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();

            $table->index(['church_id', 'name']);
        });

        Schema::create('shared_file_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_file_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'shared_file_id']);
        });

        Schema::create('event_task_shared_file', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['event_task_id', 'shared_file_id']);
        });
    }
};
