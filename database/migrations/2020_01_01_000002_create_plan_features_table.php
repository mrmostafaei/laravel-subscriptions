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
        Schema::create(config('miladtech.subscriptions.tables.plan_features'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')
                ->constrained(config('miladtech.subscriptions.tables.plans'))
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('slug');
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('value');
            $table->unsignedSmallInteger('resettable_period')->default(0);
            $table->string('resettable_interval')->default('month');
            $table->unsignedMediumInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // Feature slugs are unique per plan (NOT globally) so the same
            // slug — e.g. "sms" — can exist on several plans, which is what
            // makes usage transferable when a subscription changes plan.
            $table->unique(['plan_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('miladtech.subscriptions.tables.plan_features'));
    }
};
