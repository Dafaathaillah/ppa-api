<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GenerateInspectionService;
use App\Support\Api\SiteContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GenerateInspectionController extends Controller
{
    public function __invoke(Request $request, GenerateInspectionService $service): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['ict_developer', 'ict_ho', 'ict_group_leader'], true), 403);
        $validated = $request->validate([
            'type' => ['required', Rule::in($service->types())],
            'site' => ['nullable', 'string', 'max:25'],
        ]);
        $site = SiteContext::resolve($request);
        SiteContext::authorizeSite($request, $site);

        return response()->json([
            'message' => "Data inspeksi {$validated['type']} site {$site} berhasil di-generate.",
            'data' => $service->generate($validated['type'], $site),
        ]);
    }
}
