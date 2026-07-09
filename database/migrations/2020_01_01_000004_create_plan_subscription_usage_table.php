<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(config('miladtech.subscriptions.tables.plan_subscription_usage'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')
                ->constrained(config('miladtech.subscriptions.tables.plan_subscriptions'))
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('feature_id')
                ->constrained(config('miladtech.subscriptions.tables.plan_features'))
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->unsignedInteger('used')->default(0);
            $table->dateTime('valid_until')->nullable();
            $table->timestamps();

            // One usage row per subscription/feature pair — enforced by the
            // database so concurrent requests can never create duplicates.
            $table->unique(['subscription_id', 'feature_id'], 'usage_subscription_feature_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('miladtech.subscriptions.tables.plan_subscription_usage'));
    }
};
