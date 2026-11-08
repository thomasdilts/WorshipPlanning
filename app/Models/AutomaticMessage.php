<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToChurch;
use Database\Factories\AutomaticMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A message template sent automatically to everyone on each service, a set number of hours before it starts.
 */
class AutomaticMessage extends Model
{
    /** @use HasFactory<AutomaticMessageFactory> */
    use BelongsToChurch, HasFactory;

    protected $fillable = [
        'church_id',
        'name',
        'message_template_id',
        'hours_before',
        'custom_message',
        'is_active',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'hours_before' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The services this message has already been sent for.
     */
    public function sentForEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'automatic_message_deliveries')
            ->withoutGlobalScope(Event::SERVICES_SCOPE)
            ->withPivot('message_count')
            ->withTimestamps();
    }
}
