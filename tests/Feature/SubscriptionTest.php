<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use MiladTech\Subscriptions\Events\SubscriptionCanceled;
use MiladTech\Subscriptions\Events\SubscriptionExpired;
use MiladTech\Subscriptions\Events\SubscriptionPlanChanged;
use MiladTech\Subscriptions\Events\SubscriptionRenewed;
use MiladTech\Subscriptions\Events\SubscriptionTrialEnded;
use MiladTech\Subscriptions\Exceptions\InactivePlanException;
use MiladTech\Subscriptions\Exceptions\PlanSubscribersLimitReachedException;
use MiladTech\Subscriptions\Exceptions\SubscriptionException;
use MiladTech\Subscriptions\Tests\Models\User;
use MiladTech\Subscriptions\Tests\TestCase;

class SubscriptionTest extends TestCase
{
    protected function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Milad',
            'email' => uniqid('user', true).'@example.com',
        ], $overrides));
    }

    public function test_subscribing_to_plan_without_trial_leaves_trial_null(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $plan = $this->createPlan();
        $user = $this->createUser();

        $subscription = $user->newPlanSubscription('main', $plan);

        $this->assertNull($subscription->trial_ends_at);
        $this->assertSame('2026-01-01 00:00:00', $subscription->starts_at->toDateTimeString());
        $this->assertSame('2026-02-01 00:00:00', $subscription->ends_at->toDateTimeString());
        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->onTrial());
    }

    public function test_subscribing_to_plan_with_trial_sets_trial_and_period_after_trial(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $plan = $this->createPlan(['trial_period' => 7, 'trial_interval' => 'day']);
        $user = $this->createUser();

        $subscription = $user->newPlanSubscription('main', $plan);

        $this->assertSame('2026-01-08 00:00:00', $subscription->trial_ends_at->toDateTimeString());
        $this->assertSame('2026-01-08 00:00:00', $subscription->starts_at->toDateTimeString());
        $this->assertSame('2026-02-08 00:00:00', $subscription->ends_at->toDateTimeString());
        $this->assertTrue($subscription->onTrial());
        $this->assertTrue($subscription->active());
    }

    public function test_subscribing_to_inactive_plan_is_rejected(): void
    {
        $plan = $this->createPlan(['is_active' => false]);

        $this->expectException(InactivePlanException::class);

        $this->createUser()->newPlanSubscription('main', $plan);
    }

    public function test_plan_subscriber_limit_is_enforced(): void
    {
        $plan = $this->createPlan(['active_subscribers_limit' => 1]);

        $this->createUser()->newPlanSubscription('main', $plan);

        $this->expectException(PlanSubscribersLimitReachedException::class);

        $this->createUser()->newPlanSubscription('main', $plan);
    }

    public function test_two_subscribers_can_share_the_same_subscription_slug(): void
    {
        $plan = $this->createPlan();

        $first = $this->createUser()->newPlanSubscription('main', $plan);
        $second = $this->createUser()->newPlanSubscription('main', $plan);

        $this->assertSame('main', $first->slug);
        $this->assertSame('main', $second->slug);
    }

    public function test_same_subscriber_gets_suffixed_slug_for_duplicate_names(): void
    {
        $plan = $this->createPlan();
        $user = $this->createUser();

        $first = $user->newPlanSubscription('main', $plan);
        $second = $user->newPlanSubscription('main', $plan);

        $this->assertSame('main', $first->slug);
        $this->assertSame('main-2', $second->slug);
        $this->assertTrue($user->planSubscription('main')->is($first));
        $this->assertTrue($user->planSubscription('main-2')->is($second));
    }

    public function test_cancel_at_period_end_keeps_access_until_period_ends(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $subscription = $this->createUser()->newPlanSubscription('main', $this->createPlan());

        Event::fake([SubscriptionCanceled::class]);

        $subscription->cancel();

        Event::assertDispatched(SubscriptionCanceled::class);
        $this->assertTrue($subscription->canceled());
        $this->assertTrue($subscription->active());
        $this->assertSame('2026-02-01 00:00:00', $subscription->cancels_at->toDateTimeString());

        Carbon::setTestNow('2026-02-01 00:00:01');
        $this->assertFalse($subscription->active());
    }

    public function test_immediate_cancel_terminates_access_and_trial_now(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $plan = $this->createPlan(['trial_period' => 7, 'trial_interval' => 'day']);
        $subscription = $this->createUser()->newPlanSubscription('main', $plan);

        $subscription->cancel(true);

        $this->assertFalse($subscription->active());
        $this->assertFalse($subscription->onTrial());
        $this->assertSame('2026-01-01 00:00:00', $subscription->ends_at->toDateTimeString());
    }

    public function test_uncancel_reverts_scheduled_cancellation(): void
    {
        $subscription = $this->createUser()->newPlanSubscription('main', $this->createPlan());

        $subscription->cancel()->uncancel();

        $this->assertFalse($subscription->canceled());
        $this->assertNull($subscription->cancels_at);
        $this->assertTrue($subscription->active());
    }

    public function test_uncancel_after_immediate_cancel_throws(): void
    {
        $subscription = $this->createUser()->newPlanSubscription('main', $this->createPlan());

        $subscription->cancel(true);

        $this->expectException(SubscriptionException::class);

        $subscription->uncancel();
    }

    public function test_early_renewal_extends_period_and_keeps_usage(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $plan = $this->createPlan();
        $plan->features()->create(['name' => 'SMS', 'slug' => 'sms', 'value' => '100']);

        $subscription = $this->createUser()->newPlanSubscription('main', $plan);
        $subscription->recordFeatureUsage('sms', 10);

        Carbon::setTestNow('2026-01-15 00:00:00');

        Event::fake([SubscriptionRenewed::class]);
        $subscription->renew();
        Event::assertDispatched(SubscriptionRenewed::class);

        // Remaining paid time is preserved: new end = old end + 1 month.
        $this->assertSame('2026-03-01 00:00:00', $subscription->ends_at->toDateTimeString());
        $this->assertSame('2026-01-01 00:00:00', $subscription->starts_at->toDateTimeString());
        $this->assertSame(10, $subscription->getFeatureUsage('sms'));
    }

    public function test_renewal_after_expiry_starts_fresh_period_and_clears_usage(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $plan = $this->createPlan();
        $plan->features()->create(['name' => 'SMS', 'slug' => 'sms', 'value' => '100']);

        $subscription = $this->createUser()->newPlanSubscription('main', $plan);
        $subscription->recordFeatureUsage('sms', 10);
        $subscription->cancel();

        Carbon::setTestNow('2026-03-15 00:00:00'); // long expired

        $subscription->renew();

        $this->assertSame('2026-03-15 00:00:00', $subscription->starts_at->toDateTimeString());
        $this->assertSame('2026-04-15 00:00:00', $subscription->ends_at->toDateTimeString());
        $this->assertFalse($subscription->canceled());
        $this->assertTrue($subscription->active());
        $this->assertSame(0, $subscription->getFeatureUsage('sms'));
    }

    public function test_renewing_multiple_periods_at_once(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $subscription = $this->createUser()->newPlanSubscription('main', $this->createPlan());

        $subscription->renew(3);

        $this->assertSame('2026-05-01 00:00:00', $subscription->ends_at->toDateTimeString());
    }

    public function test_suspended_subscription_is_inactive_and_cannot_renew(): void
    {
        $subscription = $this->createUser()->newPlanSubscription('main', $this->createPlan());

        $subscription->suspend();

        $this->assertTrue($subscription->suspended());
        $this->assertFalse($subscription->active());

        $this->expectException(SubscriptionException::class);

        $subscription->renew();
    }

    public function test_resume_credits_paused_time_back(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $subscription = $this->createUser()->newPlanSubscription('main', $this->createPlan());

        Carbon::setTestNow('2026-01-11 00:00:00');
        $subscription->suspend();

        Carbon::setTestNow('2026-01-21 00:00:00'); // paused 10 days
        $subscription->resume();

        $this->assertFalse($subscription->suspended());
        $this->assertTrue($subscription->active());
        $this->assertSame('2026-02-11 00:00:00', $subscription->ends_at->toDateTimeString());
    }

    public function test_grace_period_keeps_subscription_active_after_period_end(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $plan = $this->createPlan(['grace_period' => 3, 'grace_interval' => 'day']);
        $subscription = $this->createUser()->newPlanSubscription('main', $plan);

        Carbon::setTestNow('2026-02-02 00:00:00'); // ended, inside grace
        $this->assertTrue($subscription->ended());
        $this->assertTrue($subscription->onGracePeriod());
        $this->assertTrue($subscription->active());

        Carbon::setTestNow('2026-02-05 00:00:00'); // grace over
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertFalse($subscription->active());
    }

    public function test_change_plan_remaps_usage_and_respects_billing_frequency(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $oldPlan = $this->createPlan(['name' => 'Old Plan']);
        $oldPlan->features()->create(['name' => 'SMS', 'slug' => 'sms', 'value' => '100']);
        $oldPlan->features()->create(['name' => 'Calls', 'slug' => 'calls', 'value' => '50']);

        $newPlan = $this->createPlan(['name' => 'New Plan']);
        $newSms = $newPlan->features()->create(['name' => 'SMS', 'slug' => 'sms', 'value' => '20']);

        $subscription = $this->createUser()->newPlanSubscription('main', $oldPlan);
        $subscription->recordFeatureUsage('sms', 10);
        $subscription->recordFeatureUsage('calls', 5);

        Event::fake([SubscriptionPlanChanged::class]);
        $subscription->changePlan($newPlan);
        Event::assertDispatched(SubscriptionPlanChanged::class);

        // Same billing frequency: period unchanged.
        $this->assertSame('2026-02-01 00:00:00', $subscription->ends_at->toDateTimeString());

        // SMS usage carried over and now counts against the lower limit.
        $this->assertSame(10, $subscription->getFeatureUsage('sms'));
        $this->assertSame(10, $subscription->getFeatureRemainings('sms'));
        $this->assertSame($newSms->getKey(), $subscription->usage()->byFeatureSlug('sms')->first()->feature_id);

        // Feature missing from the new plan: usage removed.
        $this->assertSame(0, $subscription->usage()->byFeatureSlug('calls')->count());
    }

    public function test_change_plan_with_different_billing_frequency_starts_new_period(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $subscription = $this->createUser()->newPlanSubscription('main', $this->createPlan());

        $yearly = $this->createPlan(['name' => 'Yearly', 'invoice_period' => 1, 'invoice_interval' => 'year']);

        Carbon::setTestNow('2026-01-10 00:00:00');
        $subscription->changePlan($yearly);

        $this->assertSame('2026-01-10 00:00:00', $subscription->starts_at->toDateTimeString());
        $this->assertSame('2027-01-10 00:00:00', $subscription->ends_at->toDateTimeString());
    }

    public function test_change_plan_allows_inactive_plans_for_admin_flows(): void
    {
        $subscription = $this->createUser()->newPlanSubscription('main', $this->createPlan());

        // Inactive plans are commonly private/custom plans attached manually
        // by admins — changePlan must not reject them.
        $private = $this->createPlan(['name' => 'Private Custom', 'is_active' => false]);

        $subscription->changePlan($private);

        $this->assertSame($private->getKey(), $subscription->plan_id);
    }

    public function test_new_subscription_plan_checks_can_be_skipped(): void
    {
        $inactive = $this->createPlan(['name' => 'Private', 'is_active' => false]);

        $subscription = $this->createUser()->newPlanSubscription('main', $inactive, null, true);

        $this->assertTrue($subscription->active());
        $this->assertSame($inactive->getKey(), $subscription->plan_id);
    }

    public function test_check_command_fires_expiry_and_trial_events_exactly_once(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $plan = $this->createPlan(['trial_period' => 7, 'trial_interval' => 'day']);
        $subscription = $this->createUser()->newPlanSubscription('main', $plan);

        Carbon::setTestNow('2026-03-01 00:00:00'); // trial + period ended

        Event::fake([SubscriptionExpired::class, SubscriptionTrialEnded::class]);

        $this->artisan('subscriptions:check')->assertExitCode(0);

        Event::assertDispatchedTimes(SubscriptionExpired::class, 1);
        Event::assertDispatchedTimes(SubscriptionTrialEnded::class, 1);

        // Second run must be a no-op.
        $this->artisan('subscriptions:check')->assertExitCode(0);

        Event::assertDispatchedTimes(SubscriptionExpired::class, 1);
        Event::assertDispatchedTimes(SubscriptionTrialEnded::class, 1);

        // Renewal re-arms expiry detection.
        $subscription->refresh()->renew();
        $this->assertNull($subscription->expired_notified_at);
    }

    public function test_check_command_respects_grace_period(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');

        $plan = $this->createPlan(['grace_period' => 5, 'grace_interval' => 'day']);
        $this->createUser()->newPlanSubscription('main', $plan);

        Carbon::setTestNow('2026-02-02 00:00:00'); // ended, still in grace

        Event::fake([SubscriptionExpired::class]);

        $this->artisan('subscriptions:check')->assertExitCode(0);
        Event::assertNotDispatched(SubscriptionExpired::class);

        Carbon::setTestNow('2026-02-07 00:00:00'); // grace over

        $this->artisan('subscriptions:check')->assertExitCode(0);
        Event::assertDispatchedTimes(SubscriptionExpired::class, 1);
    }

    public function test_deleting_subscription_removes_usage(): void
    {
        $plan = $this->createPlan();
        $plan->features()->create(['name' => 'SMS', 'slug' => 'sms', 'value' => '100']);

        $subscription = $this->createUser()->newPlanSubscription('main', $plan);
        $subscription->recordFeatureUsage('sms', 1);

        $usageTable = config('miladtech.subscriptions.tables.plan_subscription_usage');
        $this->assertDatabaseCount($usageTable, 1);

        $subscription->delete();

        $this->assertDatabaseCount($usageTable, 0);
    }

    public function test_deleting_plan_cascades_to_features_and_subscriptions(): void
    {
        $plan = $this->createPlan();
        $plan->features()->create(['name' => 'SMS', 'slug' => 'sms', 'value' => '100']);
        $subscription = $this->createUser()->newPlanSubscription('main', $plan);

        // This used to crash with a fatal error (undefined planSubscriptions()).
        $plan->delete();

        $this->assertSoftDeleted($subscription->getTable(), ['id' => $subscription->getKey()]);
    }

    public function test_subscribed_to_and_subscribed_plans_helpers(): void
    {
        $plan = $this->createPlan();
        $user = $this->createUser();

        $user->newPlanSubscription('main', $plan);

        $this->assertTrue($user->subscribedTo($plan->getKey()));
        $this->assertTrue($user->hasActivePlanSubscription());
        $this->assertCount(1, $user->subscribedPlans());
    }
}
