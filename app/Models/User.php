<?php

namespace App\Models;

use App\Enums\MessagePreference;
use App\Services\UserAvatarProcessor;
use App\Support\MessagePreferenceRule;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'church_id',
    'name',
    'display_name',
    'avatar_path',
    'email',
    'phone',
    'ntfy_topic',
    'password',
    'is_platform_admin',
    'message_preference',
    'language_id',
    'preferred_landing',
    'spreadsheet_preferences',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements CanResetPasswordContract, FilamentUser, HasAvatar, HasName
{
    /** @use HasFactory<UserFactory> */
    use CanResetPassword, HasFactory, HasRoles, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'message_preference' => MessagePreference::class,
            'spreadsheet_preferences' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if (! $user->isDirty('avatar_path')) {
                return;
            }

            $previous = $user->getOriginal('avatar_path');

            if (filled($user->avatar_path)) {
                $user->avatar_path = app(UserAvatarProcessor::class)
                    ->normalizeToJpeg((string) $user->avatar_path);
            }

            if (filled($previous) && $previous !== $user->avatar_path) {
                Storage::disk('public')->delete($previous);
            }
        });

        static::saved(function (User $user): void {
            if ($user->wasChanged('message_preference') || $user->wasRecentlyCreated) {
                MessagePreferenceRule::forgetConfirmations();
            }
        });

        static::deleting(function (User $user): void {
            if (filled($user->avatar_path)) {
                Storage::disk('public')->delete($user->avatar_path);
            }
        });
    }

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function unavailabilities(): HasMany
    {
        return $this->hasMany(Unavailability::class);
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(EventTask::class, 'assigned_user_id');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)
            ->withPivot(['is_leader'])
            ->withTimestamps();
    }

    public function ledTeams(): BelongsToMany
    {
        return $this->teams()->wherePivot('is_leader', true);
    }

    /**
     * Leading a team (not holding a role) is what grants team-leader powers.
     */
    public function leadsAnyTeam(): bool
    {
        return $this->church_id !== null
            && $this->ledTeams()->where('teams.church_id', $this->church_id)->exists();
    }

    /**
     * Task templates this user is approved to fill as the person assignee.
     *
     * @return BelongsToMany<TaskTemplate, $this>
     */
    public function abilityTemplates(): BelongsToMany
    {
        return $this->belongsToMany(TaskTemplate::class)
            ->withTimestamps();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    public function displayName(): string
    {
        return filled($this->display_name) ? (string) $this->display_name : (string) $this->name;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatarUrl();
    }

    public function getFilamentName(): string
    {
        return $this->displayName();
    }

    public function avatarUrl(): ?string
    {
        if (blank($this->avatar_path) || ! Storage::disk('public')->exists($this->avatar_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->avatar_path);
    }

    public function avatarInitials(): string
    {
        $parts = preg_split('/\s+/', trim($this->displayName())) ?: [];
        $parts = array_values(array_filter($parts));

        if ($parts === []) {
            return '?';
        }

        if (count($parts) === 1) {
            return Str::upper(Str::substr($parts[0], 0, 2));
        }

        return Str::upper(Str::substr($parts[0], 0, 1).Str::substr($parts[array_key_last($parts)], 0, 1));
    }

    public function avatarDirectory(): string
    {
        $churchId = $this->church_id ?? auth()->user()?->church_id ?? 'shared';

        return 'churches/'.$churchId.'/avatars';
    }
}
