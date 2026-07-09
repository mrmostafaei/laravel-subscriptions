<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Tests\Feature;

use Carbon\Carbon;
use MiladTech\Subscriptions\Exceptions\FeatureNotFoundException;
use MiladTech\Subscriptions\Exceptions\FeatureUsageExceededException;
use MiladTech\Subscriptions\Exceptions\SubscriptionException;
use MiladTech\Subscriptions\Models\Plan;
use MiladTech\Subscriptions\Models\PlanSubscription;
use MiladTech\Subscriptions\Tests\Models\User;
use MiladTech\Subscriptions\Tests\TestCase;

class FeatureUsageTest extends TestCase
{
    protected function subscriptionWithFeatures(array $features, array $planOverrides = []): PlanSubscription
    {
        $plan = $this->createPlan($planOverrides);

        foreach ($features as $feature) {
            $plan->features()->create($feature);
        }

        $user = User::create(['name' => 'Milad', 'email' => uniqid('user', true).'@example.com']);

        return $user->newPlanSubscription('main', $plan);
    }

    public function test_recording_usage_increments_and_reports_remaining(): void
    {
        $subscription = $this->subscriptionWithFeatures([
            ['name' => 'SMS', 'slug' => 'sms', 'value' => '100'],
        ]);

        $subscription->recordFeatureUsage('sms', 30);
        $subscription->recordFeatureUsage('sms', 20);

        $this->assertSame(50, $subscription->getFeatureUsage('sms'));
        $this->assertSame(50, $subscription->getFeatureRemainings('sms'));
        $this->assertTrue($subscription->canUseFeature('sms'));
        $this->assertTrue($subscription->canUseFeature('sms', 50));
        $this->assertFalse($subscription->canUseFeature('sms', 51));
    }

    public function test_exceeding_the_limit_throws_and_does_not_record(): void
    {
        $subscription = $this->subscriptionWithFeatures([
            ['name' => 'SMS', 'slug' => 'sms', 'value' => '10'],
        ]);

        $subscription->recordFeatureUsage('sms', 10);

        try {
            $subscription->recordFeatureUsage('sms', 1);
            $this->fail('Expected FeatureUsageExceededException was not thrown.');
        } catch (FeatureUsageExceededException $exception) {
            $this->assertSame('sms', $exception->featureSlug);
            $this->assertSame(0, $exception->remaining);
        }

        $this->assertSame(10, $subscription->getFeatureUsage('sms'));
        $this->assertFalse($subscription->canUseFeature('sms'));
    }

    public function test_boolean_true_feature_is_enabled_and_unlimited(): void
    {
        $subscription = $this->subscriptionWithFeatures([
            ['name' => 'API Access', 'slug' => 'api-access', 'value' => 'true'],
        ]);

        $this->assertTrue($subscription->canUseFeature('api-access'));
        $this->assertSame(PHP_INT_MAX, $subscription->getFeatureRemainings('api-access'));

        $subscription->recordFeatureUsage('api-access', 1000);
        $this->assertTrue($subscription->canUseFeature('api-access'));
    }

    public function test_disabled_feature_cannot_be_used(): void
    {
        $subscription = $this->subscriptionWithFeatures([
            ['name' => 'Old Feature', 'slug' => 'old-feature', 'value' => 'false'],
            ['name' => 'Zero Feature', 'slug' => 'zero-feature', 'value' => '0'],
        ]);

        $this->assertFalse($subscription->canUseFeature('old-feature'));
        $this->assertFalse($subscription->canUseFeature('zero-feature'));
        $this->assertSame(0, $subscription->getFeatureRemainings('old-feature'));

        $this->expectException(FeatureUsageExceededException::class);

        $subscription->recordFeatureUsage('zero-feature');
    }

    public function test_unknown_feature_throws_not_found_instead_of_fatal_error(): void
    {
        $subscription = $this->subscriptionWithFeatures([]);

        $this->assertFalse($subscription->canUseFeature('ghost'));

        $this->expectException(FeatureNotFoundException::class);

        $subscription->recordFeatureUsage('ghost');
    }

    public function test_usage_cannot_be_recorded_on_inactive_subscription(): void
    {
        $subscription = $this->subscriptionWithFeatures([
            ['name' => 'SMS', 'slug' => 'sms', 'value' => '100'],
        ]);

        $subscription->cancel(true);

        $this->assertFalse($subscription->canUseFeature('sms'));

        $this->expectException(SubscriptionException::class);

        $subscription->recordFeatureUsage('sms');
    }

    public function test_reducing_usage_never_goes_below_zero(): void
    {
        $subscription = $this->subscriptionWithFeatures([
            ['name' => 'SMS', 'slug' => 'sms', 'value' => '100'],
        ]);

        $subscription->recordFeatureUsage('sms', 5);
        $usage = $subscription->reduceFeatureUsage('sms', 999);

        $this->assertSame(0, $usage->used);
        $this->assertNull($subscription->reduceFeatureUsage('ghost'));
    }

    public function test_set_feature_usage_overrides_absolute_value(): void
    {
        $subscription = $this->subscriptionWithFeatures([
            ['name' => 'SMS', 'slug' => 'sms', 'value' => '100'],
        ]);

        $subscription->recordFeatureUsage('sms', 42);
        $subscription->setFeatureUsage('sms', 7);

        $this->assertSame(7, $subscription->getFeatureUsage('sms'));
    }

    public function test_resettable_feature_resets_after_period(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $subscription = $this->subscriptionWithFeatures([
            [
                'name' => 'SMS',
                'slug' => 'sms',
                'value' => '10',
                'resettable_period' => 1,
                'resettable_interval' => 'month',
            ],
        ], ['invoice_period' => 1, 'invoice_interval' => 'year']);

        $subscription->recordFeatureUsage('sms', 10);
        $this->assertFalse($subscription->canUseFeature('sms'));

        // Next usage period: usage is stale, reported as zero.
        Carbon::setTestNow('2026-02-02 00:00:00');
        $this->assertSame(0, $subscription->getFeatureUsage('sms'));
        $this->assertTrue($subscription->canUseFeature('sms'));

        // Recording again resets the counter and starts a fresh window.
        $usage = $subscription->recordFeatureUsage('sms', 3);
        $this->assertSame(3, $usage->used);
        $this->assertTrue($usage->valid_until->isFuture());
    }

    public function test_reset_catches_up_multiple_elapsed_periods(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $subscription = $this->subscriptionWithFeatures([
            [
                'name' => 'SMS',
                'slug' => 'sms',
                'value' => '10',
                'resettable_period' => 1,
                'resettable_interval' => 'month',
            ],
        ], ['invoice_period' => 1, 'invoice_interval' => 'year']);

        $subscription->recordFeatureUsage('sms', 5);

        // Five reset periods pass without any activity.
        Carbon::setTestNow('2026-06-15 00:00:00');

        $usage = $subscription->recordFeatureUsage('sms', 1);

        $this->assertSame(1, $usage->used);
        // valid_until must land in the future, not one stale month ahead.
        $this->assertTrue($usage->valid_until->isFuture());
    }

    public function test_feature_value_helpers(): void
    {
        $plan = $this->createPlan();
        $countable = $plan->features()->create(['name' => 'SMS', 'slug' => 'sms', 'value' => '100']);
        $boolean = $plan->features()->create(['name' => 'API', 'slug' => 'api', 'value' => 'true']);
        $disabled = $plan->features()->create(['name' => 'Legacy', 'slug' => 'legacy', 'value' => 'false']);
        $descriptive = $plan->features()->create(['name' => 'Storage', 'slug' => 'storage', 'value' => '10GB SSD']);

        $this->assertSame(100, $countable->limit());
        $this->assertFalse($countable->isDisabled());

        $this->assertNull($boolean->limit());
        $this->assertTrue($boolean->isUnlimited());

        $this->assertTrue($disabled->isDisabled());
        $this->assertNull($disabled->limit());

        $this->assertNull($descriptive->limit());
        $this->assertFalse($descriptive->isDisabled());
    }

    public function test_same_feature_slug_on_two_plans_is_isolated(): void
    {
        $planA = $this->createPlan(['name' => 'Plan A']);
        $planA->features()->create(['name' => 'SMS', 'slug' => 'sms', 'value' => '10']);

        $planB = $this->createPlan(['name' => 'Plan B']);
        $planB->features()->create(['name' => 'SMS', 'slug' => 'sms', 'value' => '999']);

        $userA = User::create(['name' => 'A', 'email' => 'a@example.com']);
        $userB = User::create(['name' => 'B', 'email' => 'b@example.com']);

        $subA = $userA->newPlanSubscription('main', $planA);
        $subB = $userB->newPlanSubscription('main', $planB);

        $subA->recordFeatureUsage('sms', 10);
        $subB->recordFeatureUsage('sms', 500);

        $this->assertSame(10, $subA->getFeatureUsage('sms'));
        $this->assertSame(0, $subA->getFeatureRemainings('sms'));
        $this->assertSame(500, $subB->getFeatureUsage('sms'));
        $this->assertSame(499, $subB->getFeatureRemainings('sms'));
    }
}
