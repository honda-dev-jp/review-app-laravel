<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        assert($app instanceof Application);

        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();

        return $app;
    }
}
