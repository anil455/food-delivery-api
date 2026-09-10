<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\RestaurantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lifts tenant scoping for the platform panel.
 *
 * The platform panel exists to look across every restaurant, so it is the one
 * place where the scope should stand down. Access is gated before this runs:
 * canAccessPanel() on User admits only super admins to the platform panel, and
 * this middleware is attached to no other panel.
 */
final class EnablePlatformCrossTenant
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->enableCrossTenant();

        return $next($request);
    }
}
