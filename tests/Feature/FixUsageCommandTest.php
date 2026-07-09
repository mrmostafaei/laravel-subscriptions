<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Tests\Feature;

use MiladTech\Subscriptions\Models\PlanFeature;
use MiladTech\Subscriptions\Tests\Models\User;
use MiladTech\Subscriptions\Tests\TestCase;

class FixUsageCommandTest extends TestCase
{
    protected function createUser(): User
    {
        return User::create(['name' => 'Milad', 'email' => uniqid('user', true).'@example.com']);
    }

    public function test_it_remaps_usage_left_on_a_previous_plans_features(): void
    {
        $oldPlan = $this->createPlan(['name' => 'Old Plan']);
        $oldSms = $oldPlan->features()->create(['name' => 'SMS', 'slug' => 'sms', 'value' => '100']);

        $newPlan = $this->createPlan(['name' => 'New Plan']);
        $newSms = $newPlan->features()->create(['name' => 'SMS', 'slug' => 'sms', 'value' => '200']);

        $subscription = $this->createUser()->newPlanSubscription('main', $oldPlan);
        $subscription->recordFeatureUsage('sms', 10);

        // Simulate the v1 changePlan bug: plan switched, usage never remapped.
        $subscription->forceFill(['plan_id' => $newPlan->getKey()])->save();

        $this->artisan('subscriptions:fix-usage')->assertExitCode(0);

        $usage = $subscription->usage()->first();
        $this->assertSame($newSms->getKey(), $usage->feature_id);
        $this->assertSame(10, $usage->used);
        $this->assertSame(10, $subscription->refresh()->getFeatureUsage('sms'));
        $this->assertNotSame($oldSms->getKey(), $usage->feature_id);
    }

    public function test_it_reports_orphans_of_trashed_features_and_prunes_on_demand(): void
    {
        $plan = $this->createPlan();
        $feature = $plan->features()->create(['name' => 'SMS', 'slug' => 'sms', 'value' => '100']);

        $subscription = $this->createUser()->newPlanSubscription('main', $plan);
        $subscription->recordFeatureUsage('sms', 7);

        // Bulk soft-delete (no model events, like raw v1 admin panels did),
        // leaving the usage row orphaned.
        PlanFeature::whereKey($feature->getKey())->update(['deleted_at' => now()]);

        $this->assertSame(0, $subscription->getFeatureUsage('sms'));

        // Without --prune the row is kept (reported as unmatched).
        $this->artisan('subscriptions:fix-usage')->assertExitCode(0);
        $this->assertSame(1, $subscription->usage()->count());

        // With --prune it is removed.
        $this->artisan('subscriptions:fix-usage', ['--prune' => true])->assertExitCode(0);
        $this->assertSame(0, $subscription->usage()->count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $oldPlan = $this->createPlan(['name' => 'Old Plan']);
        $oldPlan->features()->create(['name' => 'SMS', 'slug' => 'sms', 'value' => '100']);

        $newPlan = $this->createPlan(['name' => 'New Plan']);
        $newPlan->features()->create(['name' => 'SMS', 'slug' => 'sms', 'value' => '200']);

        $subscription = $this->createUser()->newPlanSubscription('main', $oldPlan);
        $subscription->recordFeatureUsage('sms', 10);
        $subscription->forceFill(['plan_id' => $newPlan->getKey()])->save();

        $originalFeatureId = $subscription->usage()->first()->feature_id;

        $this->artisan('subscriptions:fix-usage', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame($originalFeatureId, $subscription->usage()->first()->feature_id);
    }
}
