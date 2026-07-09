<?php

declare(strict_types=1);

namespace MiladTech\Subscriptions\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use MiladTech\Subscriptions\Traits\HasPlanSubscriptions;

class User extends Model
{
    use HasPlanSubscriptions;

    protected $table = 'users';

    protected $fillable = ['name', 'email'];
}
