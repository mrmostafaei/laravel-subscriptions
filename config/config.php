<?php

declare(strict_types=1);

return [

    // Manage autoload migrations
    'autoload_migrations' => true,

    // Subscriptions Database Tables
    'tables' => [

        'plans' => 'plans',
        'plan_features' => 'plan_features',
        'plan_subscriptions' => 'plan_subscriptions',
        'plan_subscription_usage' => 'plan_subscription_usage',

    ],

    // Subscriptions Models
    'models' => [

        'plan' => \MiladTech\Subscriptions\Models\Plan::class,
        'plan_feature' => \MiladTech\Subscriptions\Models\PlanFeature::class,
        'plan_subscription' => \MiladTech\Subscriptions\Models\PlanSubscription::class,
        'plan_subscription_usage' => \MiladTech\Subscriptions\Models\PlanSubscriptionUsage::class,

    ],

];
