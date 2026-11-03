<?php

namespace App\Providers\Filament;

use App\Core\Tenancy\CurrentChurch;
use App\Filament\Navigation\LandingDestination;
use App\Filament\Navigation\PanelNavigation;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EditProfile;
use App\Http\Middleware\SetCurrentChurch;
use App\Http\Middleware\SetUserLocale;
use App\Support\InitialsAvatarProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->homeUrl(fn (): ?string => app(LandingDestination::class)->url())
            ->brandName(fn (): string => app(CurrentChurch::class)->get()?->name
                ?? auth()->user()?->church?->name
                ?? 'WorshipPlanning')
            ->brandLogo(function (): ?string {
                $church = app(CurrentChurch::class)->get() ?? auth()->user()?->church;

                return $church?->logoUrl();
            })
            ->brandLogoHeight('4.5rem')
            ->font(fn (): string => match (auth()->user()?->language?->iso) {
                'ar' => 'Noto Naskh Arabic',
                default => 'Instrument Sans',
            })
            ->serifFont('Instrument Serif')
            ->globalSearch(false)
            ->login(Login::class)
            ->passwordReset()
            ->simplePageMaxContentWidth(Width::FiveExtraLarge)
            ->profile(EditProfile::class, isSimple: false)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'primary' => [
                    50 => '#f0fdfa',
                    100 => '#ccfbf1',
                    200 => '#99f6e4',
                    300 => '#5eead4',
                    400 => '#2dd4bf',
                    500 => '#14b8a6',
                    600 => '#0f766e',
                    700 => '#115e59',
                    800 => '#134e4a',
                    900 => '#0f3d3a',
                    950 => '#042f2e',
                ],
                'gray' => [
                    50 => '#f6f8fa',
                    100 => '#eef2f5',
                    200 => '#dce3e8',
                    300 => '#c4ced5',
                    400 => '#8fa0a9',
                    500 => '#6b7f89',
                    600 => '#52636b',
                    700 => '#3b4a50',
                    800 => '#25363a',
                    900 => '#172b2c',
                    950 => '#0e1b1c',
                ],
                'success' => Color::hex('#166534'),
                'danger' => Color::hex('#b42318'),
                'warning' => Color::hex('#b45309'),
            ])
            ->topNavigation()
            ->breadcrumbs(false)
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => auth()->check()
                ? view('filament.navigation.mobile-tabs', ['tabs' => app(PanelNavigation::class)->mobileTabs()])->render()
                    .view('filament.events.order-drag-script')->render()
                    .view('filament.users.profile-preview-host')->render()
                : '')
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder => app(PanelNavigation::class)->build($builder))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetUserLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                SetCurrentChurch::class,
                SetUserLocale::class,
            ]);
    }
}
