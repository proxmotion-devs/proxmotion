<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * docs/api/openapi.yaml must describe exactly the routes of API v1
 * (AGENTS.md 4.1). Only paths and methods are compared; response shapes are
 * asserted by the other API tests.
 */
class OpenApiContractTest extends TestCase
{
    public function test_every_api_route_is_documented_and_nothing_else()
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $route) => str_starts_with($route->uri(), 'api/v1/') && ! str_starts_with($route->uri(), 'api/v1/test-'))
            ->flatMap(fn (RouteDefinition $route) => collect($route->methods())
                ->reject(fn (string $method) => $method === 'HEAD')
                ->map(fn (string $method) => strtolower($method).' '.substr($route->uri(), strlen('api/v1'))))
            ->sort()
            ->values()
            ->all();

        $this->assertNotEmpty($routes);
        $this->assertSame($routes, $this->documentedOperations());
    }

    /**
     * "get /servers/{server}" for every operation under `paths:`.
     *
     * @return list<string>
     */
    private function documentedOperations(): array
    {
        $yaml = (string) file_get_contents(base_path('../docs/api/openapi.yaml'));
        $paths = substr($yaml, (int) strpos($yaml, "\npaths:\n"), (int) strpos($yaml, "\ncomponents:\n") - (int) strpos($yaml, "\npaths:\n"));
        $operations = [];
        $path = null;

        foreach (explode("\n", $paths) as $line) {
            if (preg_match('#^  (/\S*):\s*$#', $line, $match) === 1) {
                $path = $match[1];
            } elseif ($path !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $match) === 1) {
                $operations[] = "{$match[1]} {$path}";
            }
        }

        sort($operations);

        return $operations;
    }
}
