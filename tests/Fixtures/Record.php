<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Record extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
