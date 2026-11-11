<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToChurch;
use App\Support\DateTimeFormats;
use Database\Factories\UnavailabilityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class Unavailability extends Model
{
    /** @use HasFactory<UnavailabilityFactory> */
    use BelongsToChurch, HasFactory;

    protected $fillable = [
        'church_id',
        'user_id',
        'team_id',
        'starts_on',
        'ends_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Unavailability $record): void {
            $hasUser = filled($record->user_id);
            $hasTeam = filled($record->team_id);

            if ($hasUser === $hasTeam) {
                throw new InvalidArgumentException('Unavailability must belong to either a user or a team, not both or neither.');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isPersonal(): bool
    {
        return $this->user_id !== null;
    }

    public function isForTeam(): bool
    {
        return $this->team_id !== null;
    }

    /**
     * Inclusive date-range overlap with [from, to].
     */
    public function overlapsRange(Carbon|string $from, Carbon|string $to): bool
    {
        $fromDate = Carbon::parse($from)->toDateString();
        $toDate = Carbon::parse($to)->toDateString();

        return $this->starts_on->toDateString() <= $toDate
            && $this->ends_on->toDateString() >= $fromDate;
    }

    /**
     * @param  Builder<Unavailability>  $query
     * @return Builder<Unavailability>
     */
    public function scopeOverlapping(Builder $query, Carbon|string $from, Carbon|string $to): Builder
    {
        $fromDate = Carbon::parse($from)->toDateString();
        $toDate = Carbon::parse($to)->toDateString();

        return $query
            ->whereDate('starts_on', '<=', $toDate)
            ->whereDate('ends_on', '>=', $fromDate);
    }

    /**
     * @param  Builder<Unavailability>  $query
     * @return Builder<Unavailability>
     */
    public function scopePersonal(Builder $query): Builder
    {
        return $query->whereNotNull('user_id')->whereNull('team_id');
    }

    /**
     * @param  Builder<Unavailability>  $query
     * @return Builder<Unavailability>
     */
    public function scopeForTeams(Builder $query): Builder
    {
        return $query->whereNotNull('team_id')->whereNull('user_id');
    }

    public function label(): string
    {
        $range = $this->starts_on->translatedFormat(DateTimeFormats::date())
            .' – '
            .$this->ends_on->translatedFormat(DateTimeFormats::date());

        if ($this->starts_on->equalTo($this->ends_on)) {
            $range = $this->starts_on->translatedFormat(DateTimeFormats::date());
        }

        return $range;
    }

    public function subjectLabel(): string
    {
        if ($this->isForTeam()) {
            return $this->team?->name ?? 'Team';
        }

        return $this->user?->displayName() ?: 'Person';
    }
}
