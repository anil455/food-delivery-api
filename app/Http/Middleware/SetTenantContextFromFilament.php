<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Restaurant;
use App\Services\Tenancy\RestaurantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bridges the Filament panel tenant to the tenant context the API uses.
 *
 * Filament resolves and authorises the restaurant from the URL, then this hands
 * it to RestaurantContext so the fail-closed global scope on every model does
 * the actual filtering. Without it, the panel would throw
 * TenantContextMissingException on the first query it runs.
 *
 * The point is that the panel and the API share one isolation mechanism rather
 * than each having their own. A fix to the scope fixes both.
 */
final class SetTenantContextFromFilament
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Restaurant) {
            $this->context->set($tenant);
        }

        return $next($request);
    }
}
