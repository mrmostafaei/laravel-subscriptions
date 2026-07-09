<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToPlan
{
    /**
     * The model always belongs to a plan.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(config('miladtech.subscriptions.models.plan'), 'plan_id', 'id', 'plan');
    }

    /**
     * Scope models by plan id.
     */
    public function scopeByPlanId(Builder $builder, int|string $planId): Builder
    {
        return $builder->where('plan_id', $planId);
    }
}
