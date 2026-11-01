<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToChurch;
use Database\Factories\OutboundMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivered (or failed) message to one recipient — the log behind "My messages" and "Message history".
 */
class OutboundMessage extends Model
{
    /** @use HasFactory<OutboundMessageFactory> */
    use BelongsToChurch, HasFactory;

    public const KIND_EVENT = 'event';

    public const KIND_TEAM = 'team';

    public const KIND_SYSTEM = 'system';

    protected $fillable = [
        'church_id',
        'event_id',
        'event_message_id',
        'user_id',
        'channel',
        'subject',
        'body',
        'status',
        'error',
        'template_key',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function eventMessage(): BelongsTo
    {
        return $this->belongsTo(EventMessage::class);
    }

    /**
     * Event messages, team messages, or messages WorshipPlanner sends by itself (such as confirmations).
     */
    public function kind(): string
    {
        if ($this->event_message_id !== null) {
            return self::KIND_EVENT;
        }

        if (filled($this->meta['team_id'] ?? null)) {
            return self::KIND_TEAM;
        }

        return self::KIND_SYSTEM;
    }

    /**
     * @return array<string, string>
     */
    public static function kindOptions(): array
    {
        return [
            self::KIND_EVENT => __('Event'),
            self::KIND_TEAM => __('Team'),
            self::KIND_SYSTEM => __('System'),
        ];
    }

    /**
     * The person who wrote the message; null for system messages.
     */
    public function senderId(): ?int
    {
        if ($this->event_message_id !== null) {
            return $this->eventMessage?->created_by_user_id;
        }

        $senderId = (int) ($this->meta['from_user_id'] ?? 0);

        return $senderId === 0 ? null : $senderId;
    }

    public function scopeSentBy(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $query): Builder => $query
            ->where('meta->from_user_id', $user->id)
            ->orWhereHas('eventMessage', fn (Builder $query): Builder => $query->where('created_by_user_id', $user->id)));
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return match ($kind) {
            self::KIND_EVENT => $query->whereNotNull('event_message_id'),
            self::KIND_TEAM => $query->whereNull('event_message_id')->whereNotNull('meta->team_id'),
            default => $query->whereNull('event_message_id')->whereNull('meta->team_id'),
        };
    }
}
