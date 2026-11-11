<?php

namespace App\Enums;

/**
 * The effective, derived state of one position in a service.
 *
 * Availability conflicts are deliberately not part of this: they are independent of the response.
 */
enum AssignmentState: string
{
    case Vacant = 'vacant';
    case Draft = 'draft';
    case Informed = 'informed';
    case Invited = 'invited';
    case Confirmed = 'confirmed';
    case ConfirmedVerbally = 'confirmed_verbally';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case AssumedTeam = 'assumed_team';
    case Named = 'named';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Vacant => __('Vacant'),
            self::Draft => __('Assigned, not invited'),
            self::Informed => __('Informed'),
            self::Invited => __('No response'),
            self::Confirmed => __('Accepted'),
            self::ConfirmedVerbally => __('Accepted verbally'),
            self::Declined => __('Declined'),
            self::Cancelled => __('Cancelled'),
            self::AssumedTeam => __('Team assumed'),
            self::Named => __('Guest'),
            self::Unknown => __('Unknown'),
        };
    }

    /**
     * Matches a .wp-status-* class in the panel theme.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Confirmed, self::ConfirmedVerbally => 'confirmed',
            self::Invited => 'awaiting',
            self::Declined, self::Cancelled => 'declined',
            self::Draft => 'draft',
            default => 'neutral',
        };
    }

    /**
     * Chips worth showing on a dense grid. Assumed-team remains a quiet default.
     */
    public function showsOnGrid(): bool
    {
        return in_array($this, [
            self::Draft,
            self::Invited,
            self::Confirmed,
            self::ConfirmedVerbally,
            self::Declined,
            self::Cancelled,
            self::Unknown,
        ], true);
    }

    public function icon(): string
    {
        return match ($this) {
            self::Confirmed => 'heroicon-m-check-circle',
            self::ConfirmedVerbally => 'heroicon-m-chat-bubble-left-right',
            self::Invited => 'heroicon-m-clock',
            self::Declined => 'heroicon-m-x-circle',
            self::Cancelled => 'heroicon-m-minus-circle',
            self::Draft => 'heroicon-m-pencil-square',
            self::Vacant => 'heroicon-m-user-plus',
            default => 'heroicon-m-information-circle',
        };
    }

    public function needsAttention(): bool
    {
        return in_array($this, [self::Vacant, self::Declined, self::Cancelled, self::Unknown], true);
    }
}
