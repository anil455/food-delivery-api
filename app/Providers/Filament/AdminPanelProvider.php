<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Tenancy\RestaurantProfile;
use App\Http\Middleware\SetTenantContextFromFilament;
use App\Models\Restaurant;
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
 * The restaurant admin panel.
 *
 * Tenant-scoped on Restaurant, which lines up exactly with the API: staff work
 * on one restaurant at a time, and the restaurant is proven from membership
 * rather than taken from input.
 *
 * Filament has its own tenant scoping, but this panel does not rely on it for
 * isolation. SetTenantContextFromFilament hands the chosen restaurant to the
 * same RestaurantContext the API uses, so the fail-closed global scope on every
 * model does the filtering. One mechanism, one place to get right.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Restaurant Admin')
            ->colors([
                'primary' => Color::Amber,
            ])

            /*
             * The restaurant switcher in the sidebar. Staff see only the
             * restaurants they hold a membership for; super admins see all.
             */
            ->tenant(Restaurant::class, slugAttribute: 'slug')
            ->tenantProfile(RestaurantProfile::class)

            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')

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
            ])

            // Runs after the tenant is resolved, so the API tenant context and
            // the panel always agree on which restaurant is in play.
            ->tenantMiddleware([
                SetTenantContextFromFilament::class,
            ], isPersistent: true);
    }
}
