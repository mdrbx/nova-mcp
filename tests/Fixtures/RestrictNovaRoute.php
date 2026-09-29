<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictNovaRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        abort(403, 'Restricted by Nova route middleware.');
    }
}
