<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['can_use_nova' => 'boolean'];
    }
}
