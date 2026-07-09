<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MiladTech\Subscriptions\Models\PlanSubscriptionUsage.
 *
 * @property int                 $id
 * @property int                 $subscription_id
 * @property int                 $feature_id
 * @property int                 $used
 * @property \Carbon\Carbon|null $valid_until
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read \MiladTech\Subscriptions\Models\PlanFeature      $feature
 * @property-read \MiladTech\Subscriptions\Models\PlanSubscription $subscription
 */
class PlanSubscriptionUsage extends Model
{
    /**
     * {@inheritdoc}
     */
    protected $fillable = [
        'subscription_id',
        'feature_id',
        'used',
        'valid_until',
    ];

    /**
     * {@inheritdoc}
     */
    protected $casts = [
        'subscription_id' => 'integer',
        'feature_id' => 'integer',
        'used' => 'integer',
        'valid_until' => 'datetime',
    ];

    /**
     * {@inheritdoc}
     */
    protected $attributes = [
        'used' => 0,
    ];

    /**
     * Create a new Eloquent model instance.
     */
    public function __construct(array $attributes = [])
    {
        $this->setTable(config('miladtech.subscriptions.tables.plan_subscription_usage'));

        parent::__construct($attributes);
    }

    /**
     * Subscription usage always belongs to a plan feature.
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(config('miladtech.subscriptions.models.plan_feature'), 'feature_id', 'id', 'feature');
    }

    /**
     * Subscription usage always belongs to a plan subscription.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(config('miladtech.subscriptions.models.plan_subscription'), 'subscription_id', 'id', 'subscription');
    }

    /**
     * Scope subscription usage by feature slug.
     *
     * Matched through the feature relation so features of other
     * plans that happen to share the slug are never picked up.
     */
    public function scopeByFeatureSlug(Builder $builder, string $featureSlug): Builder
    {
        return $builder->whereHas('feature', function (Builder $query) use ($featureSlug): void {
            $query->where('slug', $featureSlug);
        });
    }

    /**
     * Check whether usage has been expired or not.
     */
    public function expired(): bool
    {
        if ($this->valid_until === null) {
            return false;
        }

        return Carbon::now()->gte($this->valid_until);
    }
}
