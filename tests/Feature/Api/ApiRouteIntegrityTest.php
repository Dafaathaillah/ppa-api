<?php

namespace Tests\Feature\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiRouteIntegrityTest extends TestCase
{
    public function test_specialized_inventory_routes_take_precedence_over_generic_registry_routes(): void
    {
        $expectedControllers = [
            '/api/inventory/tabs' => \App\Http\Controllers\Api\InventoryTabController::class,
            '/api/inventory/nvrs' => \App\Http\Controllers\Api\InventoryNvrController::class,
            '/api/inventory/servers' => \App\Http\Controllers\Api\InventoryServerController::class,
            '/api/inventory/starlinks' => \App\Http\Controllers\Api\InventoryStarlinkController::class,
            '/api/inventory/panel-boxes' => \App\Http\Controllers\Api\InventoryPanelBoxController::class,
            '/api/inventory/towers' => \App\Http\Controllers\Api\InventoryTowerController::class,
            '/api/inventory/access-points' => \App\Http\Controllers\Api\InventoryAccessPointController::class,
            '/api/inventory/switches' => \App\Http\Controllers\Api\InventorySwitchController::class,
            '/api/inventory/wireless' => \App\Http\Controllers\Api\InventoryWirelessController::class,
        ];

        foreach ($expectedControllers as $uri => $controller) {
            $route = Route::getRoutes()->match(Request::create($uri, 'GET'));
            $this->assertStringStartsWith($controller.'@', $route->getActionName(), "Wrong route precedence for {$uri}");
        }
    }

    public function test_source_backed_api_dependencies_are_autoloadable(): void
    {
        $dependencies = [
            \App\Exports\DeliverAssetTemplateExport::class,
            \App\Imports\DeliverAssetImport::class,
            \App\Imports\DeliverAssetPreviewImport::class,
            \App\Support\MutationAssetPdfStorage::class,
            \App\Support\TowerInspectionPhotoStorage::class,
        ];

        foreach ($dependencies as $dependency) {
            $this->assertTrue(class_exists($dependency), "Missing API dependency: {$dependency}");
        }
    }

    public function test_only_documented_api_controller_classes_are_missing(): void
    {
        $missingClasses = [];
        $missingMethods = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $action = $route->getActionName();
            if (! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);
            if (! class_exists($class)) {
                $missingClasses[] = $class;

                continue;
            }

            if (! method_exists($class, $method)) {
                $missingMethods[] = "{$class}@{$method}";
            }
        }

        $this->assertSame([], array_values(array_unique($missingClasses)));
        $this->assertSame([], array_values(array_unique($missingMethods)));
    }
}
