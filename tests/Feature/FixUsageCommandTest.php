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

    public function test_match_base_remaps_v1_suffixed_slugs(): void
    {
        // v1 forced globally-unique feature slugs, so the same logical
        // feature got suffixed across plans: "treatment", "treatment-3", ...
        $oldPlan = $this->createPlan(['name' => 'Old Plan']);
        $oldTreatment = $oldPlan->features()->create(['name' => 'Treatment', 'slug' => 'treatment-3', 'value' => '10']);

        $newPlan = $this->createPlan(['name' => 'New Plan']);
        $newTreatment = $newPlan->features()->create(['name' => 'Treatment', 'slug' => 'treatment', 'value' => '20']);

        $subscription = $this->createUser()->newPlanSubscription('main', $oldPlan);
        $subscription->recordFeatureUsage('treatment-3', 4);
        $subscription->forceFill(['plan_id' => $newPlan->getKey()])->save();

        // Exact matching leaves it untouched...
        $this->artisan('subscriptions:fix-usage')->assertExitCode(0);
        $this->assertSame($oldTreatment->getKey(), $subscription->usage()->first()->feature_id);

        // ...base matching remaps it.
        $this->artisan('subscriptions:fix-usage', ['--match-base' => true])->assertExitCode(0);

        $usage = $subscription->usage()->first();
        $this->assertSame($newTreatment->getKey(), $usage->feature_id);
        $this->assertSame(4, $usage->used);
        $this->assertSame(4, $subscription->refresh()->getFeatureUsage('treatment'));
    }

    public function test_match_base_skips_ambiguous_candidates(): void
    {
        $oldPlan = $this->createPlan(['name' => 'Old Plan']);
        $oldPlan->features()->create(['name' => 'Treatment', 'slug' => 'treatment-9', 'value' => '10']);

        $newPlan = $this->createPlan(['name' => 'New Plan']);
        $newPlan->features()->create(['name' => 'Treatment', 'slug' => 'treatment', 'value' => '20']);
        $newPlan->features()->create(['name' => 'Treatment Extra', 'slug' => 'treatment-2', 'value' => '30']);

        $subscription = $this->createUser()->newPlanSubscription('main', $oldPlan);
        $subscription->recordFeatureUsage('treatment-9', 4);
        $originalFeatureId = $subscription->usage()->first()->feature_id;
        $subscription->forceFill(['plan_id' => $newPlan->getKey()])->save();

        $this->artisan('subscriptions:fix-usage', ['--match-base' => true])->assertExitCode(0);

        // Two candidates ("treatment", "treatment-2") — nothing is guessed.
        $this->assertSame($originalFeatureId, $subscription->usage()->first()->feature_id);
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
