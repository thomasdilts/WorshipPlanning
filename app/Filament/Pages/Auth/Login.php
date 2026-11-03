<?php

namespace App\Filament\Pages\Auth;

use App\Services\TurnstileVerifier;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    protected Width|string|null $maxWidth = Width::FiveExtraLarge;

    /** Set by the Cloudflare Turnstile widget; each token works once. */
    public ?string $turnstileToken = null;

    public function authenticate(): ?LoginResponse
    {
        $turnstile = app(TurnstileVerifier::class);

        if ($turnstile->isEnabled()) {
            $token = $this->turnstileToken;
            $this->turnstileToken = null;
            $this->dispatch('turnstile-reset');

            if (! $turnstile->passes($token, request()->ip())) {
                throw ValidationException::withMessages([
                    'data.email' => __('We could not check that you are not a robot. Wait a moment and try again.'),
                ]);
            }
        }

        return parent::authenticate();
    }

    public function getHeading(): string|Htmlable|null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getHeading();
        }

        // Heading lives in the login column so the aside can lead with its own title.
        return null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'lg' => 2])
                    ->schema([
                        Group::make([
                            View::make('filament.auth.login-heading')
                                ->visible(fn (): bool => blank($this->userUndertakingMultiFactorAuthentication)),
                            RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE),
                            View::make('filament.auth.turnstile')
                                ->viewData(fn (): array => ['siteKey' => app(TurnstileVerifier::class)->siteKey()])
                                ->visible(fn (): bool => app(TurnstileVerifier::class)->isEnabled()),
                            $this->getFormContentComponent(),
                            $this->getMultiFactorChallengeFormContentComponent(),
                            RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER),
                        ]),
                        View::make('filament.auth.login-aside')
                            ->visible(fn (): bool => blank($this->userUndertakingMultiFactorAuthentication)),
                    ]),
            ]);
    }
}
