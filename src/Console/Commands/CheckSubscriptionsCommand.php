<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use MiladTech\Subscriptions\Events\SubscriptionExpired;
use MiladTech\Subscriptions\Events\SubscriptionTrialEnded;
use MiladTech\Subscriptions\Models\PlanSubscription;

/**
 * Fires SubscriptionTrialEnded / SubscriptionExpired events exactly once
 * per subscription. Schedule it to run regularly, e.g. in routes/console.php:
 *
 *     Schedule::command('subscriptions:check')->everyFifteenMinutes();
 */
class CheckSubscriptionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect ended trials and expired subscriptions (grace period aware) and fire their events once.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $model = config('miladtech.subscriptions.models.plan_subscription', PlanSubscription::class);

        $trials = 0;
        $expirations = 0;

        // 1) Trials that ended and were never announced.
        $model::query()
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', Carbon::now())
            ->whereNull('trial_ended_notified_at')
            ->chunkById(100, function ($subscriptions) use (&$trials): void {
                foreach ($subscriptions as $subscription) {
                    $subscription->forceFill(['trial_ended_notified_at' => Carbon::now()])->save();
                    event(new SubscriptionTrialEnded($subscription));
                    $trials++;
                }
            });

        // 2) Periods that ended — grace period is evaluated per plan.
        $model::query()
            ->with('plan')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', Carbon::now())
            ->whereNull('expired_notified_at')
            ->chunkById(100, function ($subscriptions) use (&$expirations): void {
                foreach ($subscriptions as $subscription) {
                    $graceEndsAt = $subscription->graceEndsAt();

                    if ($graceEndsAt !== null && $graceEndsAt->isFuture()) {
                        continue; // still inside grace period
                    }

                    $subscription->forceFill(['expired_notified_at' => Carbon::now()])->save();
                    event(new SubscriptionExpired($subscription));
                    $expirations++;
                }
            });

        $this->info("Processed {$trials} ended trial(s) and {$expirations} expired subscription(s).");

        return self::SUCCESS;
    }
}
