<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade migration for installs coming from v1.x.
 * Publish it with:
 *   php artisan vendor:publish --tag=miladtech-subscriptions-upgrade
 * Fresh installs must NOT run this file — the base migrations already
 * contain these columns.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table(config('miladtech.subscriptions.tables.plan_subscriptions'), function (Blueprint $table): void {
            $table->dateTime('suspended_at')->nullable()->after('canceled_at');
            $table->dateTime('expired_notified_at')->nullable()->after('suspended_at');
            $table->dateTime('trial_ended_notified_at')->nullable()->after('expired_notified_at');
        });

        Schema::table(config('miladtech.subscriptions.tables.plan_subscriptions'), function (Blueprint $table): void {
            // Slug becomes unique per subscriber instead of globally.
            $table->dropUnique(['slug']);
            $table->unique(['subscriber_type', 'subscriber_id', 'slug'], 'plan_subscriptions_subscriber_slug_unique');
            $table->index('ends_at');
            $table->index('trial_ends_at');
        });

        Schema::table(config('miladtech.subscriptions.tables.plan_features'), function (Blueprint $table): void {
            // Feature slug becomes unique per plan instead of globally.
            $table->dropUnique(['slug']);
            $table->unique(['plan_id', 'slug']);
        });

        Schema::table(config('miladtech.subscriptions.tables.plan_subscription_usage'), function (Blueprint $table): void {
            $table->unique(['subscription_id', 'feature_id'], 'usage_subscription_feature_unique');
        });
    }

    public function down(): void
    {
        Schema::table(config('miladtech.subscriptions.tables.plan_subscription_usage'), function (Blueprint $table): void {
            $table->dropUnique('usage_subscription_feature_unique');
        });

        Schema::table(config('miladtech.subscriptions.tables.plan_features'), function (Blueprint $table): void {
            $table->dropUnique(['plan_id', 'slug']);
            $table->unique('slug');
        });

        Schema::table(config('miladtech.subscriptions.tables.plan_subscriptions'), function (Blueprint $table): void {
            $table->dropIndex(['ends_at']);
            $table->dropIndex(['trial_ends_at']);
            $table->dropUnique('plan_subscriptions_subscriber_slug_unique');
            $table->unique('slug');
            $table->dropColumn(['suspended_at', 'expired_notified_at', 'trial_ended_notified_at']);
        });
    }
};
