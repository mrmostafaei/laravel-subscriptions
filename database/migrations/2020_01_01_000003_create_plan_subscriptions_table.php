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
        Schema::create(config('miladtech.subscriptions.tables.plan_subscriptions'), function (Blueprint $table): void {
            $table->id();
            $table->morphs('subscriber');
            $table->foreignId('plan_id')
                ->constrained(config('miladtech.subscriptions.tables.plans'))
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('slug');
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('timezone')->nullable();
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('cancels_at')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->dateTime('expired_notified_at')->nullable();
            $table->dateTime('trial_ended_notified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Slug is unique per subscriber (NOT globally) so every
            // subscriber can own a subscription slugged e.g. "main".
            $table->unique(['subscriber_type', 'subscriber_id', 'slug'], 'plan_subscriptions_subscriber_slug_unique');
            $table->index('ends_at');
            $table->index('trial_ends_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('miladtech.subscriptions.tables.plan_subscriptions'));
    }
};
