<?php

namespace Tests;

use Fuel\Lpg\LpgServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LpgServiceProvider::class,
        ];
    }
}
