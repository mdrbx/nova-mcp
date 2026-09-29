<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Tests\Fixtures;

use Illuminate\Http\Request;

class ForbiddenResource extends RecordResource
{
    public static function uriKey(): string
    {
        return 'forbidden-records';
    }

    public static function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}
