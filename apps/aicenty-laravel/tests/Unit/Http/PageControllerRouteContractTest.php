<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\PageController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PageControllerRouteContractTest extends TestCase
{
    public function test_public_route_actions_exist(): void
    {
        foreach (['index', 'contact', 'show'] as $method) {
            self::assertTrue(method_exists(PageController::class, $method), $method);
            self::assertTrue((new ReflectionMethod(PageController::class, $method))->isPublic(), $method);
        }
    }

    public function test_error_view_links_to_the_registered_home_route(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 3) . '/resources/views/default/layout/error.blade.php'
        );

        self::assertStringContainsString("route('home')", $view);
        self::assertStringNotContainsString("route('index')", $view);
    }
}
