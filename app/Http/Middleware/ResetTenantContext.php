<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\RestaurantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clears the tenant context at the start of every API request.
 *
 * Under php-fpm each request already gets a fresh container, so this changes
 * nothing in production today. It matters in two places:
 *
 *   - Octane, where the container is reused between requests and a leaked
 *     context would serve one customer the tenant resolved for another
 *   - the test suite, where the container is shared across requests inside a
 *     single test method
 *
 * The second is why this exists. Without it, a route missing its tenant
 * middleware still passes its tests, because the context set by the previous
 * request in the same test is still hanging around. That masked a real bug in
 * GET /cart until a live HTTP request found it.
 */
final class ResetTenantContext
{
    public function __construct(private readonly RestaurantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->forget();

        return $next($request);
    }
}
