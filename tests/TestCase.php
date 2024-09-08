<?php

namespace SoysalTan\MaryGen\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SoysalTan\MaryGen\MaryGenServiceProvider;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            MaryGenServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Perform any environment setup
    }
}
