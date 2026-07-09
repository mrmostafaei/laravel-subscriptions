<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade migration for installs coming from v7.x (v1 schema).
 *
 * Fully idempotent: every step checks the real state of the database
 * first, so it works across different v1 schema variations and can be
 * safely re-run after a partial failure (MySQL DDL is not transactional).
 *
 * Publish it with:
 *   php artisan vendor:publish --tag=miladtech-subscriptions-upgrade
 *
 * Fresh installs must NOT run this file — the base migrations already
 * contain these columns and indexes.
 */
return new class () extends Migration {
    public function up(): void
    {
        $subscriptionsTable = config('miladtech.subscriptions.tables.plan_subscriptions');
        $featuresTable = config('miladtech.subscriptions.tables.plan_features');
        $usageTable = config('miladtech.subscriptions.tables.plan_subscription_usage');

        // 1) New columns on plan_subscriptions.
        Schema::table($subscriptionsTable, function (Blueprint $table) use ($subscriptionsTable): void {
            if (! Schema::hasColumn($subscriptionsTable, 'suspended_at')) {
                $table->dateTime('suspended_at')->nullable()->after('canceled_at');
            }

            if (! Schema::hasColumn($subscriptionsTable, 'expired_notified_at')) {
                $table->dateTime('expired_notified_at')->nullable()->after('suspended_at');
            }

            if (! Schema::hasColumn($subscriptionsTable, 'trial_ended_notified_at')) {
                $table->dateTime('trial_ended_notified_at')->nullable()->after('expired_notified_at');
            }
        });

        // 2) Subscription slug: unique per subscriber instead of globally.
        $indexes = $this->indexNames($subscriptionsTable);

        Schema::table($subscriptionsTable, function (Blueprint $table) use ($subscriptionsTable, $indexes): void {
            if (in_array("{$subscriptionsTable}_slug_unique", $indexes, true)) {
                $table->dropUnique("{$subscriptionsTable}_slug_unique");
            }

            if (! in_array('plan_subscriptions_subscriber_slug_unique', $indexes, true)) {
                $table->unique(['subscriber_type', 'subscriber_id', 'slug'], 'plan_subscriptions_subscriber_slug_unique');
            }

            if (! in_array("{$subscriptionsTable}_ends_at_index", $indexes, true)) {
                $table->index('ends_at');
            }

            if (! in_array("{$subscriptionsTable}_trial_ends_at_index", $indexes, true)) {
                $table->index('trial_ends_at');
            }
        });

        // 3) Feature slug: unique per plan instead of globally.
        //    (Some v1 installs never had the global unique index — both cases are handled.)
        $indexes = $this->indexNames($featuresTable);

        Schema::table($featuresTable, function (Blueprint $table) use ($featuresTable, $indexes): void {
            if (in_array("{$featuresTable}_slug_unique", $indexes, true)) {
                $table->dropUnique("{$featuresTable}_slug_unique");
            }

            if (! in_array("{$featuresTable}_plan_id_slug_unique", $indexes, true)) {
                $table->unique(['plan_id', 'slug']);
            }
        });

        // 4) Usage: consolidate duplicate rows left behind by v1 race
        //    conditions, then enforce one row per subscription/feature.
        $indexes = $this->indexNames($usageTable);

        if (! in_array('usage_subscription_feature_unique', $indexes, true)) {
            $duplicates = DB::table($usageTable)
                ->select('subscription_id', 'feature_id', DB::raw('COUNT(*) as total'), DB::raw('SUM(used) as used_sum'), DB::raw('MAX(id) as keep_id'))
                ->groupBy('subscription_id', 'feature_id')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($duplicates as $duplicate) {
                DB::table($usageTable)
                    ->where('id', $duplicate->keep_id)
                    ->update(['used' => (int) $duplicate->used_sum]);

                DB::table($usageTable)
                    ->where('subscription_id', $duplicate->subscription_id)
                    ->where('feature_id', $duplicate->feature_id)
                    ->where('id', '!=', $duplicate->keep_id)
                    ->delete();
            }

            Schema::table($usageTable, function (Blueprint $table): void {
                $table->unique(['subscription_id', 'feature_id'], 'usage_subscription_feature_unique');
            });
        }
    }

    public function down(): void
    {
        $subscriptionsTable = config('miladtech.subscriptions.tables.plan_subscriptions');
        $featuresTable = config('miladtech.subscriptions.tables.plan_features');
        $usageTable = config('miladtech.subscriptions.tables.plan_subscription_usage');

        $indexes = $this->indexNames($usageTable);

        if (in_array('usage_subscription_feature_unique', $indexes, true)) {
            Schema::table($usageTable, function (Blueprint $table): void {
                $table->dropUnique('usage_subscription_feature_unique');
            });
        }

        $indexes = $this->indexNames($featuresTable);

        Schema::table($featuresTable, function (Blueprint $table) use ($featuresTable, $indexes): void {
            if (in_array("{$featuresTable}_plan_id_slug_unique", $indexes, true)) {
                $table->dropUnique("{$featuresTable}_plan_id_slug_unique");
            }

            if (! in_array("{$featuresTable}_slug_unique", $indexes, true)) {
                $table->unique('slug');
            }
        });

        $indexes = $this->indexNames($subscriptionsTable);

        Schema::table($subscriptionsTable, function (Blueprint $table) use ($subscriptionsTable, $indexes): void {
            if (in_array("{$subscriptionsTable}_ends_at_index", $indexes, true)) {
                $table->dropIndex(['ends_at']);
            }

            if (in_array("{$subscriptionsTable}_trial_ends_at_index", $indexes, true)) {
                $table->dropIndex(['trial_ends_at']);
            }

            if (in_array('plan_subscriptions_subscriber_slug_unique', $indexes, true)) {
                $table->dropUnique('plan_subscriptions_subscriber_slug_unique');
            }

            if (! in_array("{$subscriptionsTable}_slug_unique", $indexes, true)) {
                $table->unique('slug');
            }
        });

        Schema::table($subscriptionsTable, function (Blueprint $table) use ($subscriptionsTable): void {
            $columns = array_values(array_filter(
                ['suspended_at', 'expired_notified_at', 'trial_ended_notified_at'],
                fn (string $column): bool => Schema::hasColumn($subscriptionsTable, $column)
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    /**
     * All index names currently present on the given table.
     *
     * @return array<int, string>
     */
    protected function indexNames(string $table): array
    {
        return array_column(Schema::getIndexes($table), 'name');
    }
};
