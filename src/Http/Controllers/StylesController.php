<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StylesController
{
    public function __invoke(): BinaryFileResponse
    {
        return response()->file(__DIR__.'/../../../resources/css/styles.css', [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
