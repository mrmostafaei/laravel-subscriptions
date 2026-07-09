<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MiladTech\Subscriptions\Enums\Interval;
use MiladTech\Subscriptions\Services\Period;
use MiladTech\Subscriptions\Traits\BelongsToPlan;
use Pishran\LaravelPersianSlug\HasPersianSlug;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\Sluggable\SlugOptions;
use Spatie\Translatable\HasTranslations;

/**
 * MiladTech\Subscriptions\Models\PlanFeature.
 *
 * Feature `value` semantics:
 *  - "true"            => boolean feature, enabled / unlimited usage
 *  - "false", "0", ""  => disabled feature
 *  - numeric string    => countable feature with that usage limit
 *  - anything else     => descriptive feature (treated as enabled)
 *
 * @property int                                     $id
 * @property int                                     $plan_id
 * @property string                                  $slug
 * @property array                                   $name
 * @property array|null                              $description
 * @property string                                  $value
 * @property int                                     $resettable_period
 * @property \MiladTech\Subscriptions\Enums\Interval $resettable_interval
 * @property int                                     $sort_order
 * @property \Carbon\Carbon|null                     $created_at
 * @property \Carbon\Carbon|null                     $updated_at
 * @property \Carbon\Carbon|null                     $deleted_at
 * @property-read \MiladTech\Subscriptions\Models\Plan                                                          $plan
 * @property-read \Illuminate\Database\Eloquent\Collection|\MiladTech\Subscriptions\Models\PlanSubscriptionUsage[] $usage
 */
class PlanFeature extends Model implements Sortable
{
    use BelongsToPlan;
    use HasPersianSlug;
    use HasTranslations;
    use SoftDeletes;
    use SortableTrait;

    /**
     * {@inheritdoc}
     */
    protected $fillable = [
        'plan_id',
        'slug',
        'name',
        'description',
        'value',
        'resettable_period',
        'resettable_interval',
        'sort_order',
    ];

    /**
     * {@inheritdoc}
     */
    protected $casts = [
        'plan_id' => 'integer',
        'slug' => 'string',
        'value' => 'string',
        'resettable_period' => 'integer',
        'resettable_interval' => Interval::class,
        'sort_order' => 'integer',
        'deleted_at' => 'datetime',
    ];

    /**
     * {@inheritdoc}
     */
    protected $attributes = [
        'resettable_period' => 0,
        'resettable_interval' => 'month',
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
        $this->setTable(config('miladtech.subscriptions.tables.plan_features'));

        parent::__construct($attributes);
    }

    /**
     * {@inheritdoc}
     */
    protected static function boot()
    {
        parent::boot();

        static::deleted(function (self $feature): void {
            $feature->usage()->delete();
        });
    }

    /**
     * Get the options for generating the slug.
     *
     * Duplicate slugs are allowed globally (uniqueness is enforced
     * per-plan by the database) so the same feature slug — e.g. "sms" —
     * can exist on several plans. This is what makes usage data
     * transferable when a subscription changes plan.
     */
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->doNotGenerateSlugsOnUpdate()
            ->generateSlugsFrom('name')
            ->allowDuplicateSlugs()
            ->saveSlugsTo('slug');
    }

    /**
     * The plan feature may have many subscription usage.
     */
    public function usage(): HasMany
    {
        return $this->hasMany(config('miladtech.subscriptions.models.plan_subscription_usage'), 'feature_id', 'id');
    }

    /**
     * Whether usage of this feature resets periodically.
     */
    public function isResettable(): bool
    {
        return $this->resettable_period > 0;
    }

    /**
     * Whether the feature is disabled ("false", "0", "", or null).
     */
    public function isDisabled(): bool
    {
        $value = strtolower(trim((string) $this->value));

        return in_array($value, ['false', '0', ''], true);
    }

    /**
     * Whether the feature is an enabled boolean/unlimited feature ("true").
     */
    public function isUnlimited(): bool
    {
        return strtolower(trim((string) $this->value)) === 'true';
    }

    /**
     * The countable usage limit, or null when the feature is not countable
     * (boolean/unlimited or descriptive value).
     */
    public function limit(): ?int
    {
        $value = trim((string) $this->value);

        if (! is_numeric($value)) {
            return null;
        }

        $limit = (int) $value;

        return $limit > 0 ? $limit : null;
    }

    /**
     * Get feature's next reset date, starting from the given date.
     */
    public function getResetDate(CarbonInterface|string|null $dateFrom = null): Carbon
    {
        $period = new Period($this->resettable_interval, $this->resettable_period, $dateFrom ?? Carbon::now());

        return $period->getEndDate();
    }
}
