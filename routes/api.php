<?php

use App\Http\Controllers\Api\AduanApiController;
use App\Http\Controllers\Api\AduanHoApiController;
use App\Http\Controllers\Api\PengajuanAksesApiController;
use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\ChartInspeksiApiController;
use App\Http\Controllers\Api\DashboardAllSiteApiController;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\DepartmentApiController;
use App\Http\Controllers\Api\DeviceTokenApiController;
use App\Http\Controllers\Api\GuestApiController;
use App\Http\Controllers\Api\InspectionApiController;
use App\Http\Controllers\Api\InspectionScheduleApiController;
use App\Http\Controllers\Api\InventoryApiController;
use App\Http\Controllers\Api\KpiInspeksiApiController;
use App\Http\Controllers\Api\KpiResponseTimeApiController;
use App\Http\Controllers\Api\KpiVhmsApiController;
use App\Http\Controllers\Api\KpiAduanAnalysisApiController;
use App\Http\Controllers\Api\OperationsApiController;
use App\Http\Controllers\Api\PengalihanAssetApiController;
use App\Http\Controllers\Api\PicaInspeksiApiController;
use App\Http\Controllers\Api\ScannerApiController;
use App\Http\Controllers\Api\SiteApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthApiController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthApiController::class, 'me']);
        Route::post('/logout', [AuthApiController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/sites', [SiteApiController::class, 'index']);

    // FCM device token registration (Aduan push notifications).
    Route::post('/device-token', [DeviceTokenApiController::class, 'store']);
    Route::delete('/device-token', [DeviceTokenApiController::class, 'destroy']);

    Route::get('/dashboard', DashboardApiController::class);
    Route::get('/dashboard/all-site', DashboardAllSiteApiController::class);
    Route::get('/chart-inspeksi', ChartInspeksiApiController::class);

    Route::get('/aduan/meta', [AduanApiController::class, 'meta']);
    Route::get('/aduan/export-pdf', [AduanApiController::class, 'exportPdf']);
    Route::get('/aduan', [AduanApiController::class, 'index']);
    Route::post('/aduan', [AduanApiController::class, 'store']);
    Route::get('/aduan/{id}', [AduanApiController::class, 'show']);
    Route::patch('/aduan/{id}', [AduanApiController::class, 'update']);
    Route::patch('/aduan/{id}/accept', [AduanApiController::class, 'accept']);
    Route::patch('/aduan/{id}/progress', [AduanApiController::class, 'updateProgress']);
    Route::patch('/aduan/{id}/urgency', [AduanApiController::class, 'updateUrgency']);
    Route::delete('/aduan/{id}', [AduanApiController::class, 'destroy']);

    // Pengaduan HO — separate from normal Aduan (parity with web AduanHoController).
    // Static segments (/meta) registered before /{id} so they are not captured.
    Route::get('/aduan-ho/meta', [AduanHoApiController::class, 'meta']);
    Route::get('/aduan-ho', [AduanHoApiController::class, 'index']);
    Route::post('/aduan-ho', [AduanHoApiController::class, 'store']);
    Route::get('/aduan-ho/{id}', [AduanHoApiController::class, 'show']);
    Route::delete('/aduan-ho/{id}', [AduanHoApiController::class, 'destroy']);

    Route::apiResource('departments', DepartmentApiController::class)->except(['create', 'edit']);

    // Guest flow (parity with web GuestAllController + GuestReportController).
    // Everything is scoped to the authenticated user; no new tables/flows.
    Route::get('/guest/dashboard', [GuestApiController::class, 'dashboard']);
    Route::post('/guest/pengajuan-akses', [GuestApiController::class, 'pengajuanAkses']);
    Route::get('/guest/complaints/meta', [GuestApiController::class, 'complaintMeta']);
    Route::get('/guest/complaints', [GuestApiController::class, 'complaints']);
    Route::post('/guest/complaints', [GuestApiController::class, 'storeComplaint']);

    Route::get('/pengajuan-akses', [PengajuanAksesApiController::class, 'index']);
    Route::get('/pengajuan-akses/{id}', [PengajuanAksesApiController::class, 'show']);
    Route::patch('/pengajuan-akses/{id}/approve', [PengajuanAksesApiController::class, 'approve']);
    Route::delete('/pengajuan-akses/{id}', [PengajuanAksesApiController::class, 'destroy']);

    Route::get('/inventory/{type}/meta', [InventoryApiController::class, 'meta']);
    Route::get('/inventory/{type}/generate-code', [InventoryApiController::class, 'generateCode']);
    Route::get('/inventory/{type}', [InventoryApiController::class, 'index']);
    Route::post('/inventory/{type}', [InventoryApiController::class, 'store']);
    Route::get('/inventory/{type}/{id}', [InventoryApiController::class, 'show']);
    Route::match(['put', 'patch'], '/inventory/{type}/{id}', [InventoryApiController::class, 'update']);
    Route::delete('/inventory/{type}/{id}', [InventoryApiController::class, 'destroy']);

    Route::get('/kpi-vhms', [KpiVhmsApiController::class, 'index']);
    Route::get('/kpi-vhms/filter', [KpiVhmsApiController::class, 'filter']);
    Route::patch('/kpi-vhms/feedback', [KpiVhmsApiController::class, 'updateFeedback']);
    Route::post('/kpi-vhms', [KpiVhmsApiController::class, 'store']);
    Route::get('/kpi-vhms/breakdown', [KpiVhmsApiController::class, 'breakdown']);
    Route::get('/kpi-vhms/summary', [KpiVhmsApiController::class, 'summary']);

    Route::get('/operations/jobs', [OperationsApiController::class, 'jobsIndex']);
    Route::get('/operations/jobs/meta', [OperationsApiController::class, 'jobsMeta']);
    Route::post('/operations/jobs', [OperationsApiController::class, 'jobsStore']);
    Route::get('/operations/jobs/{code}', [OperationsApiController::class, 'jobsShow']);
    Route::match(['put', 'patch'], '/operations/jobs/{code}', [OperationsApiController::class, 'jobsUpdate']);
    Route::delete('/operations/jobs/{code}', [OperationsApiController::class, 'jobsDestroy']);

    Route::patch('/operations/jobs/{code}/approve', [OperationsApiController::class, 'approveJob']);
    Route::post('/operations/monitoring-jobs/approve', [OperationsApiController::class, 'approveBatch']);
    Route::get('/operations/monitoring-jobs', [OperationsApiController::class, 'monitoringIndex']);
    Route::get('/operations/monitoring-jobs/export', [OperationsApiController::class, 'monitoringExport']);

    Route::get('/operations/unschedule-jobs', [OperationsApiController::class, 'unscheduleIndex']);
    Route::get('/operations/unschedule-jobs/meta', [OperationsApiController::class, 'unscheduleMeta']);
    Route::get('/operations/unschedule-jobs/problems', [OperationsApiController::class, 'unscheduleProblems']);
    Route::post('/operations/unschedule-jobs', [OperationsApiController::class, 'unscheduleStore']);
    Route::get('/operations/unschedule-jobs/{code}', [OperationsApiController::class, 'unscheduleShow']);
    Route::match(['put', 'patch'], '/operations/unschedule-jobs/{code}', [OperationsApiController::class, 'unscheduleUpdate']);
    Route::delete('/operations/unschedule-jobs/{code}', [OperationsApiController::class, 'unscheduleDestroy']);

    Route::get('/scanners/meta', [ScannerApiController::class, 'meta']);
    Route::get('/scanners/generate-code', [ScannerApiController::class, 'generateCode']);
    Route::get('/scanners', [ScannerApiController::class, 'index']);
    Route::post('/scanners', [ScannerApiController::class, 'store']);
    Route::get('/scanners/{id}', [ScannerApiController::class, 'show']);
    Route::match(['put', 'patch'], '/scanners/{id}', [ScannerApiController::class, 'update']);
    Route::delete('/scanners/{id}', [ScannerApiController::class, 'destroy']);

    Route::get('/inspection-schedules', [InspectionScheduleApiController::class, 'index']);
    Route::match(['put', 'patch'], '/inspection-schedules/{id}', [InspectionScheduleApiController::class, 'update']);

    Route::get('/pengalihan-assets', [PengalihanAssetApiController::class, 'index']);
    Route::get('/pengalihan-assets/data', [PengalihanAssetApiController::class, 'data']);
    Route::get('/pengalihan-assets/meta', [PengalihanAssetApiController::class, 'meta']);
    Route::get('/pengalihan-assets/inventories', [PengalihanAssetApiController::class, 'inventories']);
    Route::get('/pengalihan-assets/inventory-detail', [PengalihanAssetApiController::class, 'inventoryDetail']);
    Route::get('/pengalihan-assets/user-by-nrp', [PengalihanAssetApiController::class, 'userByNrp']);
    Route::get('/pengalihan-assets/generate-code', [PengalihanAssetApiController::class, 'generateCode']);
    Route::post('/pengalihan-assets', [PengalihanAssetApiController::class, 'store']);

    Route::get('/pica-inspeksi/meta', [PicaInspeksiApiController::class, 'meta']);
    Route::get('/pica-inspeksi', [PicaInspeksiApiController::class, 'index']);
    Route::get('/pica-inspeksi/{id}', [PicaInspeksiApiController::class, 'show']);
    Route::match(['put', 'patch', 'post'], '/pica-inspeksi/{id}', [PicaInspeksiApiController::class, 'update']);

    Route::get('/kpi-aduan-analysis/chart', [KpiAduanAnalysisApiController::class, 'chart']);
    Route::get('/kpi-aduan-analysis/details', [KpiAduanAnalysisApiController::class, 'details']);

    Route::get('/kpi-response-time', [KpiResponseTimeApiController::class, 'index']);
    Route::get('/kpi-inspeksi', [KpiInspeksiApiController::class, 'index']);

    Route::get('/inspections/{type}', [InspectionApiController::class, 'index']);
    Route::get('/inspections/{type}/{id}', [InspectionApiController::class, 'show']);
    Route::match(['put', 'patch'], '/inspections/{type}/{id}', [InspectionApiController::class, 'update']);
});
