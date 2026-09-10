<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Exceptions\Api\TenantContextMissingException;
use App\Services\Tenancy\RestaurantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the current restaurant.
 *
 * Fail-closed: with no context and no explicit cross-tenant opt-in this throws
 * rather than returning unscoped rows. A forgotten middleware therefore surfaces
 * as a failing test, never as a silent cross-tenant leak.
 */
final class RestaurantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(RestaurantContext::class);

        if ($context->isCrossTenant()) {
            return;
        }

        if (! $context->has()) {
            throw new TenantContextMissingException($model::class);
        }

        $builder->where(
            $model->qualifyColumn('restaurant_id'),
            $context->requireId(),
        );
    }
}
