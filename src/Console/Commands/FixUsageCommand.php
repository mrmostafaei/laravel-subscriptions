<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repairs orphaned feature-usage rows left behind by v1:
 *
 *  - usage pointing at soft-deleted features (plan features edited by
 *    delete + recreate),
 *  - usage pointing at features of a previous plan (v1 changePlan never
 *    remapped usage),
 *  - duplicate rows for the same subscription/feature pair (merged, summed).
 *
 * Rows are remapped to the live feature with the same slug on the
 * subscription's current plan. Run with --dry-run first to preview.
 */
class FixUsageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:fix-usage
                            {--dry-run : Report what would change without writing}
                            {--match-base : When no exact slug match exists, match by base slug ignoring the numeric suffix v1 appended (e.g. "treatment-3" -> "treatment")}
                            {--prune : Delete usage rows that cannot be matched to any feature on the current plan}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remap orphaned feature usage rows (from v1 data) to the live features of each subscription\'s current plan.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $usageModel = config('miladtech.subscriptions.models.plan_subscription_usage');
        $featureModel = config('miladtech.subscriptions.models.plan_feature');

        $dryRun = (bool) $this->option('dry-run');
        $prune = (bool) $this->option('prune');
        $matchBase = (bool) $this->option('match-base');

        $healthy = 0;
        $remapped = 0;
        $merged = 0;
        $deleted = 0;
        $unmatched = 0;

        $run = function () use ($usageModel, $featureModel, $dryRun, $prune, $matchBase, &$healthy, &$remapped, &$merged, &$deleted, &$unmatched): void {
            $usageModel::query()
                ->with([
                    'feature' => fn ($query) => $query->withTrashed(),
                    'subscription' => fn ($query) => $query->withTrashed(),
                ])
                ->chunkById(200, function ($rows) use ($usageModel, $featureModel, $dryRun, $prune, $matchBase, &$healthy, &$remapped, &$merged, &$deleted, &$unmatched): void {
                    foreach ($rows as $usage) {
                        $subscription = $usage->subscription;

                        // Usage without a subscription is garbage.
                        if ($subscription === null) {
                            $deleted++;
                            $this->line("  usage #{$usage->id}: no subscription — delete");

                            if (! $dryRun) {
                                $usage->delete();
                            }

                            continue;
                        }

                        $feature = $usage->feature;

                        // Healthy: live feature belonging to the subscription's current plan.
                        if ($feature !== null && ! $feature->trashed() && (int) $feature->plan_id === (int) $subscription->plan_id) {
                            $healthy++;

                            continue;
                        }

                        $slug = $feature?->slug;

                        $target = $slug === null ? null : $featureModel::query()
                            ->where('plan_id', $subscription->plan_id)
                            ->where('slug', $slug)
                            ->first();

                        // v1 enforced globally-unique feature slugs and appended
                        // numeric suffixes ("treatment-3"). With --match-base we
                        // fall back to the base slug — only when the match is
                        // unambiguous (exactly one candidate on the plan).
                        if ($target === null && $matchBase && $slug !== null) {
                            $target = $this->findByBaseSlug($featureModel, (int) $subscription->plan_id, $slug);
                        }

                        if ($target === null) {
                            if ($prune) {
                                $deleted++;
                                $this->line("  usage #{$usage->id}: feature '".($slug ?? 'unknown')."' not on current plan — delete (--prune)");

                                if (! $dryRun) {
                                    $usage->delete();
                                }
                            } else {
                                $unmatched++;
                                $this->line("  usage #{$usage->id}: feature '".($slug ?? 'unknown')."' not on current plan — left untouched (use --prune to delete)");
                            }

                            continue;
                        }

                        // A row for the target feature may already exist (unique index!)
                        // — merge by summing, otherwise simply remap.
                        $existing = $usageModel::query()
                            ->where('subscription_id', $usage->subscription_id)
                            ->where('feature_id', $target->getKey())
                            ->where('id', '!=', $usage->getKey())
                            ->first();

                        if ($existing !== null) {
                            $merged++;
                            $this->line("  usage #{$usage->id}: merged into #{$existing->id} (feature '{$slug}', used {$usage->used} + {$existing->used})");

                            if (! $dryRun) {
                                $existing->used += $usage->used;

                                if ($existing->valid_until === null && $usage->valid_until !== null) {
                                    $existing->valid_until = $usage->valid_until;
                                }

                                $existing->save();
                                $usage->delete();
                            }

                            continue;
                        }

                        $remapped++;
                        $this->line("  usage #{$usage->id}: feature '{$slug}' remapped #{$usage->feature_id} → #{$target->getKey()}");

                        if (! $dryRun) {
                            $usage->feature_id = $target->getKey();
                            $usage->save();
                        }
                    }
                });
        };

        $dryRun ? $run() : DB::transaction($run);

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '')."Healthy: {$healthy} | Remapped: {$remapped} | Merged: {$merged} | Deleted: {$deleted} | Unmatched: {$unmatched}");

        if ($unmatched > 0 && ! $prune) {
            $this->comment('Unmatched rows kept. Re-run with --match-base to match ignoring v1 numeric slug suffixes, or --prune to delete them.');
        }

        return self::SUCCESS;
    }

    /**
     * Find the single feature on the plan whose base slug matches the
     * given (possibly suffixed) slug. Returns null when zero or more
     * than one candidate exists — ambiguity is never guessed.
     */
    protected function findByBaseSlug(string $featureModel, int $planId, string $slug): ?object
    {
        $base = preg_replace('/-\d+$/u', '', $slug);

        if ($base === '' || $base === null) {
            return null;
        }

        $candidates = $featureModel::query()
            ->where('plan_id', $planId)
            ->where(function ($query) use ($base): void {
                $query->where('slug', $base)->orWhere('slug', 'LIKE', $base.'-%');
            })
            ->get()
            ->filter(fn (object $feature): bool => preg_match('/^'.preg_quote($base, '/').'(-\d+)?$/u', (string) $feature->slug) === 1)
            ->values();

        if ($candidates->count() !== 1) {
            if ($candidates->count() > 1) {
                $this->warn("  ambiguous base-slug match for '{$slug}' on plan #{$planId} (".$candidates->pluck('slug')->implode(', ').') — skipped');
            }

            return null;
        }

        return $candidates->first();
    }
}
