<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\InspectionApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class GenericInspectionRouteTest extends TestCase
{
    public function test_registry_inspections_expose_process_and_approval_routes(): void
    {
        $process = Route::getRoutes()->match(Request::create('/api/inspections/laptop/example/process', 'POST'));
        $approval = Route::getRoutes()->match(Request::create('/api/inspections/computer/example/approval', 'POST'));

        $this->assertSame(InspectionApiController::class.'@process', $process->getActionName());
        $this->assertSame(InspectionApiController::class.'@approval', $approval->getActionName());
    }

    public function test_specialized_routes_keep_priority_over_the_registry(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/inspections/nvrs/'.Str::uuid().'/process', 'POST'));

        $this->assertStringContainsString('NvrInspectionController@process', $route->getActionName());
    }
}
