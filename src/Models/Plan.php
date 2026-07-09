<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MiladTech\Subscriptions\Enums\Interval;
use Pishran\LaravelPersianSlug\HasPersianSlug;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\Sluggable\SlugOptions;
use Spatie\Translatable\HasTranslations;

/**
 * MiladTech\Subscriptions\Models\Plan.
 *
 * @property int                                     $id
 * @property string                                  $slug
 * @property array                                   $name
 * @property array|null                              $description
 * @property bool                                    $is_active
 * @property float                                   $price
 * @property float                                   $signup_fee
 * @property string                                  $currency
 * @property int                                     $trial_period
 * @property \MiladTech\Subscriptions\Enums\Interval $trial_interval
 * @property int                                     $invoice_period
 * @property \MiladTech\Subscriptions\Enums\Interval $invoice_interval
 * @property int                                     $grace_period
 * @property \MiladTech\Subscriptions\Enums\Interval $grace_interval
 * @property int|null                                $prorate_day
 * @property int|null                                $prorate_period
 * @property int|null                                $prorate_extend_due
 * @property int|null                                $active_subscribers_limit
 * @property int                                     $sort_order
 * @property \Carbon\Carbon|null                     $created_at
 * @property \Carbon\Carbon|null                     $updated_at
 * @property \Carbon\Carbon|null                     $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection|\MiladTech\Subscriptions\Models\PlanFeature[]      $features
 * @property-read \Illuminate\Database\Eloquent\Collection|\MiladTech\Subscriptions\Models\PlanSubscription[] $subscriptions
 */
class Plan extends Model implements Sortable
{
    use HasFactory;
    use HasPersianSlug;
    use HasTranslations;
    use SoftDeletes;
    use SortableTrait;

    /**
     * {@inheritdoc}
     */
    protected $fillable = [
        'slug',
        'name',
        'description',
        'is_active',
        'price',
        'signup_fee',
        'currency',
        'trial_period',
        'trial_interval',
        'invoice_period',
        'invoice_interval',
        'grace_period',
        'grace_interval',
        'prorate_day',
        'prorate_period',
        'prorate_extend_due',
        'active_subscribers_limit',
        'sort_order',
    ];

    /**
     * {@inheritdoc}
     */
    protected $casts = [
        'slug' => 'string',
        'is_active' => 'boolean',
        'price' => 'float',
        'signup_fee' => 'float',
        'currency' => 'string',
        'trial_period' => 'integer',
        'trial_interval' => Interval::class,
        'invoice_period' => 'integer',
        'invoice_interval' => Interval::class,
        'grace_period' => 'integer',
        'grace_interval' => Interval::class,
        'prorate_day' => 'integer',
        'prorate_period' => 'integer',
        'prorate_extend_due' => 'integer',
        'active_subscribers_limit' => 'integer',
        'sort_order' => 'integer',
        'deleted_at' => 'datetime',
    ];

    /**
     * Sane defaults so a plan is always in a valid state,
     * even when created with the bare minimum of attributes.
     *
     * {@inheritdoc}
     */
    protected $attributes = [
        'is_active' => true,
        'price' => 0,
        'signup_fee' => 0,
        'trial_period' => 0,
        'trial_interval' => 'day',
        'invoice_period' => 1,
        'invoice_interval' => 'month',
        'grace_period' => 0,
        'grace_interval' => 'day',
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    public $translatable = [
        'name',
        'description',
    ];

    /**
     * The sortable settings.
     *
     * @var array
     */
    public $sortable = [
        'order_column_name' => 'sort_order',
    ];

    /**
     * Create a new Eloquent model instance.
     */
    public function __construct(array $attributes = [])
    {
        $this->setTable(config('miladtech.subscriptions.tables.plans'));

        parent::__construct($attributes);
    }

    /**
     * {@inheritdoc}
     */
    protected static function boot()
    {
        parent::boot();

        // Cascade soft deletes through model events so that
        // child models run their own cleanup logic too.
        static::deleted(function (self $plan): void {
            $plan->features()->get()->each->delete();
            $plan->subscriptions()->get()->each->delete();
        });
    }

    /**
     * Get the options for generating the slug.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->doNotGenerateSlugsOnUpdate()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    /**
     * The plan may have many features.
     */
    public function features(): HasMany
    {
        return $this->hasMany(config('miladtech.subscriptions.models.plan_feature'), 'plan_id', 'id');
    }

    /**
     * The plan may have many subscriptions.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(config('miladtech.subscriptions.models.plan_subscription'), 'plan_id', 'id');
    }

    /**
     * Count subscriptions that are still within their paid period.
     */
    public function activeSubscriptionsCount(): int
    {
        return $this->subscriptions()
            ->where('ends_at', '>', now())
            ->whereNull('suspended_at')
            ->count();
    }

    /**
     * Check if plan is free.
     */
    public function isFree(): bool
    {
        return (float) $this->price <= 0.00;
    }

    /**
     * Check if plan has trial.
     */
    public function hasTrial(): bool
    {
        return $this->trial_period > 0;
    }

    /**
     * Check if plan has grace.
     */
    public function hasGrace(): bool
    {
        return $this->grace_period > 0;
    }

    /**
     * Check if the plan has room for new active subscribers.
     */
    public function hasSubscriberCapacity(): bool
    {
        if ($this->active_subscribers_limit === null || $this->active_subscribers_limit <= 0) {
            return true;
        }

        return $this->activeSubscriptionsCount() < $this->active_subscribers_limit;
    }

    /**
     * Get plan feature by the given slug.
     */
    public function getFeatureBySlug(string $featureSlug): ?PlanFeature
    {
        return $this->features()->where('slug', $featureSlug)->first();
    }

    /**
     * Activate the plan.
     *
     * @return $this
     */
    public function activate(): static
    {
        $this->update(['is_active' => true]);

        return $this;
    }

    /**
     * Deactivate the plan.
     *
     * @return $this
     */
    public function deactivate(): static
    {
        $this->update(['is_active' => false]);

        return $this;
    }
}
