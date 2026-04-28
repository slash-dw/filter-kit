<?php

declare(strict_types=1);

namespace SlashDw\FilterKit\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use SlashDw\CoreKit\CoreKitServiceProvider;
use SlashDw\FilterKit\FilterKitServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [
            CoreKitServiceProvider::class,
            FilterKitServiceProvider::class,
        ];
    }
}
