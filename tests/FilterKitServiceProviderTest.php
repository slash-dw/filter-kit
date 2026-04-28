<?php

declare(strict_types=1);

namespace SlashDw\FilterKit\Tests;

use Illuminate\Foundation\Application;
use SlashDw\CoreKit\Http\Responses\ApiResponseFactory;

final class FilterKitServiceProviderTest extends TestCase
{
    public function test_application_boots_with_core_kit_and_filter_kit(): void
    {
        $app = $this->requireLaravelApplication();
        $this->assertInstanceOf(ApiResponseFactory::class, $app->make(ApiResponseFactory::class));
    }

    private function requireLaravelApplication(): Application
    {
        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);

        return $app;
    }
}
