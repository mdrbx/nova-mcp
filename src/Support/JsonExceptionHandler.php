<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Throwable;

class JsonExceptionHandler extends Handler
{
    public function __construct(Container $container, private readonly ExceptionHandler $reporter)
    {
        parent::__construct($container);
    }

    public function report(Throwable $e): void
    {
        $this->reporter->report($e);
    }
}
