<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\EnablePlatformCrossTenant;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The platform panel: onboarding and oversight of restaurants themselves.
 *
 * Deliberately a second panel rather than a section of the admin panel. The
 * admin panel is tenant-scoped by design — every page there is about one
 * restaurant. Creating a restaurant, approving it or suspending it has no
 * tenant, so it does not belong inside that scope.
 *
 * Only super admins get in, enforced by canAccessPanel() on User. A different
 * colour makes it obvious at a glance which panel you are looking at.
 */
class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('platform')
            ->path('platform')
            ->login()
            // A dedicated guard so a platform login and a restaurant admin
            // login can coexist in one browser instead of one replacing the
            // other — both use session storage, they just keep separate
            // "who's logged in" state within it.
            ->authGuard('platform')
            ->brandName('Platform')
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->discoverResources(in: app_path('Filament/Platform/Resources'), for: 'App\Filament\Platform\Resources')
            ->discoverPages(in: app_path('Filament/Platform/Pages'), for: 'App\Filament\Platform\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Platform/Widgets'), for: 'App\Filament\Platform\Widgets')
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
            ])
            ->authMiddleware([
                Authenticate::class,
                // After authentication, so an unauthenticated request can never
                // reach cross-tenant mode.
                EnablePlatformCrossTenant::class,
            ]);
    }
}
