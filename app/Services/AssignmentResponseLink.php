<?php

namespace App\Services;

use App\Models\AssignmentResponseToken;
use App\Models\EventTask;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\URL;

class AssignmentResponseLink
{
    protected const TOKEN_ALPHABET = 'abcdefghijkmnopqrstuvwxyz23456789';

    protected const TOKEN_LENGTH = 12;

    public function showUrl(EventTask $task, User $user, int $daysValid = 14): string
    {
        return URL::temporarySignedRoute(
            'assignments.respond.show',
            now()->addDays($daysValid),
            [
                'task' => $task->id,
                'user' => $user->id,
            ],
        );
    }

    public function storeUrl(EventTask $task, User $user, int $daysValid = 14): string
    {
        return URL::temporarySignedRoute(
            'assignments.respond.store',
            now()->addDays($daysValid),
            [
                'task' => $task->id,
                'user' => $user->id,
            ],
        );
    }

    /**
     * One token per task and person, reused on every send; its expiry follows the event date.
     */
    public function tokenFor(EventTask $task, User $user): AssignmentResponseToken
    {
        $token = AssignmentResponseToken::query()->firstOrNew([
            'event_task_id' => $task->id,
            'user_id' => $user->id,
        ]);

        $token->token ??= $this->uniqueToken();
        $token->expires_at = $this->expiryFor($task);
        $token->save();

        return $token;
    }

    public function respondUrl(EventTask $task, User $user): string
    {
        return route('assignments.quick.show', ['token' => $this->tokenFor($task, $user)->token]);
    }

    public function acceptUrl(EventTask $task, User $user): string
    {
        return route('assignments.quick.answer', ['token' => $this->tokenFor($task, $user)->token, 'answer' => 'yes']);
    }

    public function declineUrl(EventTask $task, User $user): string
    {
        return route('assignments.quick.answer', ['token' => $this->tokenFor($task, $user)->token, 'answer' => 'no']);
    }

    /**
     * Values for the {{accept_link}}, {{decline_link}}, {{accept_url}} and {{decline_url}} placeholders.
     *
     * @return array{text: array<string, string>, html: array<string, string>}
     */
    public function placeholderValues(?EventTask $task, User $user): array
    {
        if ($task === null) {
            $empty = ['accept_link' => '', 'decline_link' => '', 'accept_url' => '', 'decline_url' => ''];

            return ['text' => $empty, 'html' => $empty];
        }

        $token = $this->tokenFor($task, $user)->token;
        $accept = route('assignments.quick.answer', ['token' => $token, 'answer' => 'yes']);
        $decline = route('assignments.quick.answer', ['token' => $token, 'answer' => 'no']);

        return [
            'text' => [
                'accept_link' => $accept,
                'decline_link' => $decline,
                'accept_url' => $accept,
                'decline_url' => $decline,
            ],
            'html' => [
                'accept_link' => $this->buttonHtml($accept, __('Yes, I can help'), '#0f766e', '#ffffff'),
                'decline_link' => $this->buttonHtml($decline, __("No, I can't make it"), '#fee4e2', '#b42318'),
                'accept_url' => e($accept),
                'decline_url' => e($decline),
            ],
        ];
    }

    public function footerText(EventTask $task, User $user): string
    {
        return "\n\n".__('Respond to this invitation:').' '.$this->respondUrl($task, $user);
    }

    public function footerHtml(EventTask $task, User $user): string
    {
        $url = e($this->respondUrl($task, $user));

        return '<p style="margin-top:1.5rem;font-size:14px;line-height:1.4">'
            .e(__('Respond to this invitation:'))
            .' <a href="'.$url.'">'.e(__('Open response page')).'</a>'
            .'</p>';
    }

    protected function buttonHtml(string $url, string $label, string $background, string $color): string
    {
        return '<a href="'.e($url).'" style="display:inline-block;margin:4px 8px 4px 0;padding:10px 18px;'
            .'border-radius:8px;background:'.$background.';color:'.$color.';font-weight:600;text-decoration:none">'
            .e($label).'</a>';
    }

    protected function expiryFor(EventTask $task): CarbonInterface
    {
        $task->loadMissing('event');
        $eventEnd = $task->event?->endsAt();

        if ($eventEnd === null || $eventEnd->isPast()) {
            return now()->addDays(30);
        }

        return $eventEnd->copy()->addDay();
    }

    protected function uniqueToken(): string
    {
        do {
            $token = '';

            for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
                $token .= self::TOKEN_ALPHABET[random_int(0, strlen(self::TOKEN_ALPHABET) - 1)];
            }
        } while (AssignmentResponseToken::query()->where('token', $token)->exists());

        return $token;
    }
}
