<?php

use App\Http\Controllers\Api\AccessPointInspectionController;
use App\Http\Controllers\Api\AduanApiController;
use App\Http\Controllers\Api\AduanHoApiController;
use App\Http\Controllers\Api\AssetDeliveryController;
use App\Http\Controllers\Api\AssetMutationController;
use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\CctvInspectionController;
use App\Http\Controllers\Api\ChartInspeksiApiController;
use App\Http\Controllers\Api\DailyServerMonitoringController;
use App\Http\Controllers\Api\DashboardAllSiteApiController;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\DepartmentApiController;
use App\Http\Controllers\Api\DeviceTokenApiController;
use App\Http\Controllers\Api\GuestApiController;
use App\Http\Controllers\Api\InspectionApiController;
use App\Http\Controllers\Api\InspectionScheduleApiController;
use App\Http\Controllers\Api\InventoryAccessPointController;
use App\Http\Controllers\Api\InventoryApiController;
use App\Http\Controllers\Api\InventoryNvrController;
use App\Http\Controllers\Api\InventoryPanelBoxController;
use App\Http\Controllers\Api\InventoryServerController;
use App\Http\Controllers\Api\InventoryStarlinkController;
use App\Http\Controllers\Api\InventorySwitchController;
use App\Http\Controllers\Api\InventoryTabController;
use App\Http\Controllers\Api\InventoryTowerController;
use App\Http\Controllers\Api\InventoryWirelessController;
use App\Http\Controllers\Api\KpiAduanAnalysisApiController;
use App\Http\Controllers\Api\KpiInspeksiApiController;
use App\Http\Controllers\Api\KpiResponseTimeApiController;
use App\Http\Controllers\Api\KpiVhmsApiController;
use App\Http\Controllers\Api\MobileTowerInspectionController;
use App\Http\Controllers\Api\NvrInspectionController;
use App\Http\Controllers\Api\OperationsApiController;
use App\Http\Controllers\Api\PanelBoxInspectionController;
use App\Http\Controllers\Api\PasswordBankAllSiteController;
use App\Http\Controllers\Api\PasswordBankController;
use App\Http\Controllers\Api\PengajuanAksesApiController;
use App\Http\Controllers\Api\PengalihanAssetApiController;
use App\Http\Controllers\Api\PicaInspeksiApiController;
use App\Http\Controllers\Api\GenerateInspectionController;
use App\Http\Controllers\Api\ScannerApiController;
use App\Http\Controllers\Api\ScannerInspectionController;
use App\Http\Controllers\Api\ServerInspectionController;
use App\Http\Controllers\Api\SiteApiController;
use App\Http\Controllers\Api\StockController as ApiStockController;
use App\Http\Controllers\Api\StockTransactionController;
use App\Http\Controllers\Api\SwitchInspectionController;
use App\Http\Controllers\Api\TowerInspectionController;
use App\Http\Controllers\Api\VhmsUnitController;
use App\Http\Controllers\Api\WirelessInspectionController;
use App\Http\Controllers\UnitProblemVhmsController;
use App\Http\Controllers\UnitVhmsController;
use App\Http\Controllers\VhmsRepairHistoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthApiController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthApiController::class, 'me']);
        Route::post('/logout', [AuthApiController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->prefix('daily-server-monitorings')->name('api.daily-server-monitorings.')->group(function () {
    Route::get('options', [DailyServerMonitoringController::class, 'options'])->name('options');
    Route::post('approval', [DailyServerMonitoringController::class, 'approval'])->name('approval');
    Route::get('/', [DailyServerMonitoringController::class, 'index'])->name('index');
    Route::post('/', [DailyServerMonitoringController::class, 'store'])->name('store');
    Route::get('{monitoring}', [DailyServerMonitoringController::class, 'show'])->whereUuid('monitoring')->name('show');
    Route::match(['post', 'patch'], '{monitoring}', [DailyServerMonitoringController::class, 'update'])->whereUuid('monitoring')->name('update');
    Route::delete('{monitoring}', [DailyServerMonitoringController::class, 'destroy'])->whereUuid('monitoring')->name('destroy');
});

Route::middleware('auth:sanctum')->prefix('vhms/{site}')->name('api.vhms.')->group(function () {
    Route::get('options', [VhmsUnitController::class, 'options'])->name('options');
    Route::get('units', [UnitVhmsController::class, 'data'])->name('units.index');
    Route::post('units', [VhmsUnitController::class, 'store'])->name('units.store');
    Route::get('units/{unit}', [VhmsUnitController::class, 'show'])->whereNumber('unit')->name('units.show');
    Route::match(['put', 'patch'], 'units/{unit}', [VhmsUnitController::class, 'update'])->whereNumber('unit')->name('units.update');
    Route::delete('units/{unit}', [VhmsUnitController::class, 'destroy'])->whereNumber('unit')->name('units.destroy');
    Route::get('payload', [UnitVhmsController::class, 'payload'])->name('payload');
    Route::post('evidence/import', [UnitVhmsController::class, 'importEvidence'])->name('evidence.import');
    Route::get('evidence/export', [UnitVhmsController::class, 'exportEvidence'])->name('evidence.export');
    Route::get('evidence/template', [UnitVhmsController::class, 'evidenceTemplate'])->name('evidence.template');
    Route::get('problems', [UnitProblemVhmsController::class, 'data'])->name('problems.index');
    Route::get('problems/export', [UnitProblemVhmsController::class, 'export'])->name('problems.export');
    Route::post('problems/{unit}/repairs', [UnitProblemVhmsController::class, 'store'])->whereNumber('unit')->name('problems.repairs.store');
    Route::get('problems/{unit}/repairs', [UnitProblemVhmsController::class, 'history'])->whereNumber('unit')->name('problems.repairs.index');
    Route::post('cases/{case}/complete', [UnitProblemVhmsController::class, 'complete'])->whereNumber('case')->name('cases.complete');
    Route::get('repair-history', [VhmsRepairHistoryController::class, 'data'])->name('repair-history.index');
});

Route::middleware('auth:sanctum')->prefix('asset-deliveries')->name('api.asset-deliveries.')->group(function () {
    Route::get('options', [AssetDeliveryController::class, 'options'])->name('options');
    Route::get('laptops', [AssetDeliveryController::class, 'laptops'])->name('laptops');
    Route::get('users', [AssetDeliveryController::class, 'users'])->name('users');
    Route::get('next-inventory-code', [AssetDeliveryController::class, 'nextInventoryCode'])->name('next-code');
    Route::post('import/preview', [AssetDeliveryController::class, 'importPreview'])->name('import.preview');
    Route::post('import', [AssetDeliveryController::class, 'importStore'])->name('import.store');
    Route::get('import/template', [AssetDeliveryController::class, 'template'])->name('import.template');
    Route::get('/', [AssetDeliveryController::class, 'index'])->name('index');
    Route::post('/', [AssetDeliveryController::class, 'store'])->name('store');
    Route::get('{delivery}', [AssetDeliveryController::class, 'show'])->whereUuid('delivery')->name('show');
    Route::post('{delivery}/receive', [AssetDeliveryController::class, 'receive'])->whereUuid('delivery')->name('receive');
    Route::post('{delivery}/reject', [AssetDeliveryController::class, 'reject'])->whereUuid('delivery')->name('reject');
    Route::delete('{delivery}', [AssetDeliveryController::class, 'destroy'])->whereUuid('delivery')->name('destroy');
});

Route::middleware('auth:sanctum')->prefix('asset-mutations')->name('api.asset-mutations.')->group(function () {
    Route::get('options', [AssetMutationController::class, 'options'])->name('options');
    Route::get('devices', [AssetMutationController::class, 'devices'])->name('devices');
    Route::get('devices/{category}/{asset}/user', [AssetMutationController::class, 'deviceUser'])->whereUuid('asset')->name('devices.user');
    Route::get('next-inventory-code', [AssetMutationController::class, 'nextInventoryCode'])->name('next-code');
    Route::get('/', [AssetMutationController::class, 'index'])->name('index');
    Route::post('/', [AssetMutationController::class, 'store'])->name('store');
    Route::get('{mutation}', [AssetMutationController::class, 'show'])->whereUuid('mutation')->name('show');
    Route::post('{mutation}', [AssetMutationController::class, 'update'])->whereUuid('mutation')->name('update');
    Route::delete('{mutation}', [AssetMutationController::class, 'destroy'])->whereUuid('mutation')->name('destroy');
    Route::post('{mutation}/accept', [AssetMutationController::class, 'accept'])->whereUuid('mutation')->name('accept');
    Route::post('{mutation}/reject', [AssetMutationController::class, 'reject'])->whereUuid('mutation')->name('reject');
});

Route::middleware('auth:sanctum')->prefix('inventory/stocks')->name('api.inventory.stocks.')->group(function () {
    Route::get('options', [ApiStockController::class, 'options'])->name('options');
    Route::get('next-code', [ApiStockController::class, 'nextCode'])->name('next-code');
    Route::get('transactions', [StockTransactionController::class, 'index'])->name('transactions.index');
    Route::post('transactions', [StockTransactionController::class, 'store'])->name('transactions.store');
    Route::get('transactions/{transaction}', [StockTransactionController::class, 'show'])->whereUuid('transaction')->name('transactions.show');
    Route::post('transfers', [StockTransactionController::class, 'transfer'])->name('transfers.store');
    Route::post('adjustments/bulk', [StockTransactionController::class, 'bulkAdjust'])->name('adjustments.bulk');
    Route::get('/', [ApiStockController::class, 'index'])->name('index');
    Route::post('/', [ApiStockController::class, 'store'])->name('store');
    Route::get('{stock}', [ApiStockController::class, 'show'])->whereUuid('stock')->name('show');
    Route::match(['put', 'patch'], '{stock}', [ApiStockController::class, 'update'])->whereUuid('stock')->name('update');
    Route::delete('{stock}', [ApiStockController::class, 'destroy'])->whereUuid('stock')->name('destroy');
});

Route::middleware('auth:sanctum')->prefix('inspections/towers')->name('api.inspections.towers.')->group(function () {
    Route::get('/', [TowerInspectionController::class, 'index'])->name('index');
    Route::get('{inspection}', [TowerInspectionController::class, 'show'])->name('show');
    Route::post('{inspection}/process', [TowerInspectionController::class, 'process'])->name('process');
    Route::post('{inspection}/approval', [TowerInspectionController::class, 'approval'])->name('approval');
});

Route::middleware('auth:sanctum')->prefix('inspections/scanners')->name('api.inspections.scanners.')->group(function () {
    Route::get('/', [ScannerInspectionController::class, 'index'])->name('index');
    Route::get('{inspection}', [ScannerInspectionController::class, 'show'])->whereUuid('inspection')->name('show');
    Route::post('{inspection}/process', [ScannerInspectionController::class, 'process'])->whereUuid('inspection')->name('process');
    Route::post('{inspection}/approval', [ScannerInspectionController::class, 'approval'])->whereUuid('inspection')->name('approval');
});

Route::middleware('auth:sanctum')->prefix('inspections/servers')->name('api.inspections.servers.')->group(function () {
    Route::get('/', [ServerInspectionController::class, 'index'])->name('index');
    Route::get('{inspection}', [ServerInspectionController::class, 'show'])->whereUuid('inspection')->name('show');
    Route::post('{inspection}/process', [ServerInspectionController::class, 'process'])->whereUuid('inspection')->name('process');
    Route::post('{inspection}/approval', [ServerInspectionController::class, 'approval'])->whereUuid('inspection')->name('approval');
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
    Route::post('/guest/complaints/{id}/rating', [GuestApiController::class, 'rateComplaint']);

    Route::get('/pengajuan-akses', [PengajuanAksesApiController::class, 'index']);
    Route::get('/pengajuan-akses/{id}', [PengajuanAksesApiController::class, 'show']);
    Route::patch('/pengajuan-akses/{id}/approve', [PengajuanAksesApiController::class, 'approve']);
    Route::delete('/pengajuan-akses/{id}', [PengajuanAksesApiController::class, 'destroy']);

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
    Route::post('/inspections/generate', GenerateInspectionController::class);

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

});

Route::middleware('auth:sanctum')->prefix('password-bank/all-sites')->name('api.password-bank.all-sites.')->group(function () {
    Route::get('categories', [PasswordBankAllSiteController::class, 'categories'])->name('categories');
    Route::get('/', [PasswordBankAllSiteController::class, 'index'])->name('index');
    Route::post('/', [PasswordBankAllSiteController::class, 'store'])->name('store');
    Route::get('{passwordBank}', [PasswordBankAllSiteController::class, 'show'])->whereNumber('passwordBank')->name('show');
    Route::match(['put', 'patch'], '{passwordBank}', [PasswordBankAllSiteController::class, 'update'])->whereNumber('passwordBank')->name('update');
    Route::delete('{passwordBank}', [PasswordBankAllSiteController::class, 'destroy'])->whereNumber('passwordBank')->name('destroy');
});

Route::middleware('auth:sanctum')->prefix('password-bank')->name('api.password-bank.site.')->group(function () {
    Route::get('categories', [PasswordBankController::class, 'categories'])->name('categories');
    Route::get('/', [PasswordBankController::class, 'index'])->name('index');
    Route::post('/', [PasswordBankController::class, 'store'])->name('store');
    Route::get('{passwordBank}', [PasswordBankController::class, 'show'])->whereNumber('passwordBank')->name('show');
    Route::match(['put', 'patch'], '{passwordBank}', [PasswordBankController::class, 'update'])->whereNumber('passwordBank')->name('update');
    Route::delete('{passwordBank}', [PasswordBankController::class, 'destroy'])->whereNumber('passwordBank')->name('destroy');
});

Route::middleware('auth:sanctum')->prefix('inventory/tabs')->name('api.inventory.tabs.')->group(function () {
    Route::get('options', [InventoryTabController::class, 'options'])->name('options');
    Route::get('/', [InventoryTabController::class, 'index'])->name('index');
    Route::post('/', [InventoryTabController::class, 'store'])->name('store');
    Route::get('{tab}', [InventoryTabController::class, 'show'])->whereUuid('tab')->name('show');
    Route::match(['put', 'patch', 'post'], '{tab}', [InventoryTabController::class, 'update'])->whereUuid('tab')->name('update');
    Route::delete('{tab}', [InventoryTabController::class, 'destroy'])->whereUuid('tab')->name('destroy');
});

Route::middleware('auth:sanctum')->prefix('inventory/nvrs')->name('api.inventory.nvrs.')->group(function () {
    Route::get('options', [InventoryNvrController::class, 'options'])->name('options');
    Route::get('next-number', [InventoryNvrController::class, 'nextNumber'])->name('next-number');
    Route::post('sync-channels', [InventoryNvrController::class, 'syncChannels'])->name('sync-channels');
    Route::get('/', [InventoryNvrController::class, 'index'])->name('index');
    Route::post('/', [InventoryNvrController::class, 'store'])->name('store');
    Route::get('{nvr}', [InventoryNvrController::class, 'show'])->whereUuid('nvr')->name('show');
    Route::match(['put', 'patch'], '{nvr}', [InventoryNvrController::class, 'update'])->whereUuid('nvr')->name('update');
    Route::delete('{nvr}', [InventoryNvrController::class, 'destroy'])->whereUuid('nvr')->name('destroy');
});

Route::middleware('auth:sanctum')->prefix('inventory/servers')->name('api.inventory.servers.')->group(function () {
    Route::get('options', [InventoryServerController::class, 'options'])->name('options');
    Route::get('next-number', [InventoryServerController::class, 'nextNumber'])->name('next-number');
    Route::get('/', [InventoryServerController::class, 'index'])->name('index');
    Route::post('/', [InventoryServerController::class, 'store'])->name('store');
    Route::get('{server}', [InventoryServerController::class, 'show'])->whereUuid('server')->name('show');
    Route::match(['put', 'patch'], '{server}', [InventoryServerController::class, 'update'])->whereUuid('server')->name('update');
    Route::delete('{server}', [InventoryServerController::class, 'destroy'])->whereUuid('server')->name('destroy');
});

Route::middleware('auth:sanctum')->prefix('inventory/starlinks')->name('api.inventory.starlinks.')->group(function () {
    Route::get('options', [InventoryStarlinkController::class, 'options'])->name('options');
    Route::get('next-number', [InventoryStarlinkController::class, 'nextNumber'])->name('next-number');
    Route::get('/', [InventoryStarlinkController::class, 'index'])->name('index');
    Route::post('/', [InventoryStarlinkController::class, 'store'])->name('store');
    Route::get('{starlink}', [InventoryStarlinkController::class, 'show'])->whereUuid('starlink')->name('show');
    Route::match(['put', 'patch'], '{starlink}', [InventoryStarlinkController::class, 'update'])->whereUuid('starlink')->name('update');
    Route::delete('{starlink}', [InventoryStarlinkController::class, 'destroy'])->whereUuid('starlink')->name('destroy');
});

Route::middleware('auth:sanctum')->prefix('inventory/panel-boxes')->name('api.inventory.panel-boxes.')->group(function () {
    Route::get('options', [InventoryPanelBoxController::class, 'options'])->name('options');
    Route::get('next-number', [InventoryPanelBoxController::class, 'nextNumber'])->name('next-number');
    Route::get('/', [InventoryPanelBoxController::class, 'index'])->name('index');
    Route::post('/', [InventoryPanelBoxController::class, 'store'])->name('store');
    Route::get('{panelBox}', [InventoryPanelBoxController::class, 'show'])->whereUuid('panelBox')->name('show');
    Route::match(['put', 'patch', 'post'], '{panelBox}', [InventoryPanelBoxController::class, 'update'])->whereUuid('panelBox')->name('update');
    Route::delete('{panelBox}', [InventoryPanelBoxController::class, 'destroy'])->whereUuid('panelBox')->name('destroy');
});

Route::middleware('auth:sanctum')->prefix('inventory/towers')->name('api.inventory.towers.')->group(function () {
    Route::get('options', [InventoryTowerController::class, 'options'])->name('options');
    Route::get('/', [InventoryTowerController::class, 'index'])->name('index');
    Route::post('/', [InventoryTowerController::class, 'store'])->name('store');
    Route::get('{tower}', [InventoryTowerController::class, 'show'])->name('show');
    Route::match(['put', 'patch'], '{tower}', [InventoryTowerController::class, 'update'])->name('update');
    Route::delete('{tower}', [InventoryTowerController::class, 'destroy'])->name('destroy');
});

Route::middleware('auth:sanctum')->prefix('inventory/access-points')->name('api.inventory.access-points.')->group(function () {
    Route::get('options', [InventoryAccessPointController::class, 'options'])->name('options');
    Route::get('next-number', [InventoryAccessPointController::class, 'nextNumber'])->name('next-number');
    Route::get('/', [InventoryAccessPointController::class, 'index'])->name('index');
    Route::post('/', [InventoryAccessPointController::class, 'store'])->name('store');
    Route::get('{device}', [InventoryAccessPointController::class, 'show'])->whereUuid('device')->name('show');
    Route::match(['put', 'patch'], '{device}', [InventoryAccessPointController::class, 'update'])->whereUuid('device')->name('update');
    Route::delete('{device}', [InventoryAccessPointController::class, 'destroy'])->whereUuid('device')->name('destroy');
});

Route::middleware('auth:sanctum')->prefix('inventory/switches')->name('api.inventory.switches.')->group(function () {
    Route::get('options', [InventorySwitchController::class, 'options'])->name('options');
    Route::get('next-number', [InventorySwitchController::class, 'nextNumber'])->name('next-number');
    Route::get('/', [InventorySwitchController::class, 'index'])->name('index');
    Route::post('/', [InventorySwitchController::class, 'store'])->name('store');
    Route::get('{device}', [InventorySwitchController::class, 'show'])->whereUuid('device')->name('show');
    Route::match(['put', 'patch'], '{device}', [InventorySwitchController::class, 'update'])->whereUuid('device')->name('update');
    Route::delete('{device}', [InventorySwitchController::class, 'destroy'])->whereUuid('device')->name('destroy');
});

Route::middleware('auth:sanctum')->prefix('inventory/wireless')->name('api.inventory.wireless.')->group(function () {
    Route::get('options', [InventoryWirelessController::class, 'options'])->name('options');
    Route::get('next-number', [InventoryWirelessController::class, 'nextNumber'])->name('next-number');
    Route::get('/', [InventoryWirelessController::class, 'index'])->name('index');
    Route::post('/', [InventoryWirelessController::class, 'store'])->name('store');
    Route::get('{device}', [InventoryWirelessController::class, 'show'])->whereUuid('device')->name('show');
    Route::match(['put', 'patch'], '{device}', [InventoryWirelessController::class, 'update'])->whereUuid('device')->name('update');
    Route::delete('{device}', [InventoryWirelessController::class, 'destroy'])->whereUuid('device')->name('destroy');
});

Route::middleware('auth:sanctum')->prefix('inspections/access-points')->name('api.inspections.access-points.')->group(function () {
    Route::get('/', [AccessPointInspectionController::class, 'index'])->name('index');
    Route::get('{inspection}', [AccessPointInspectionController::class, 'show'])->name('show');
    Route::post('{inspection}/process', [AccessPointInspectionController::class, 'process'])->name('process');
    Route::post('{inspection}/approval', [AccessPointInspectionController::class, 'approval'])->name('approval');
});

Route::middleware('auth:sanctum')->prefix('inspections/switches')->name('api.inspections.switches.')->group(function () {
    Route::get('/', [SwitchInspectionController::class, 'index'])->name('index');
    Route::get('{inspection}', [SwitchInspectionController::class, 'show'])->name('show');
    Route::post('{inspection}/process', [SwitchInspectionController::class, 'process'])->name('process');
    Route::post('{inspection}/approval', [SwitchInspectionController::class, 'approval'])->name('approval');
});

Route::middleware('auth:sanctum')->prefix('inspections/wireless')->name('api.inspections.wireless.')->group(function () {
    Route::get('/', [WirelessInspectionController::class, 'index'])->name('index');
    Route::get('{inspection}', [WirelessInspectionController::class, 'show'])->name('show');
    Route::post('{inspection}/process', [WirelessInspectionController::class, 'process'])->name('process');
    Route::post('{inspection}/approval', [WirelessInspectionController::class, 'approval'])->name('approval');
});

Route::middleware('auth:sanctum')->prefix('inspections/mobile-towers')->group(function () {
    Route::get('/', [MobileTowerInspectionController::class, 'index']);
    Route::get('{inspection}', [MobileTowerInspectionController::class, 'show'])->whereUuid('inspection');
    Route::post('{inspection}/process', [MobileTowerInspectionController::class, 'process'])->whereUuid('inspection');
    Route::post('{inspection}/approval', [MobileTowerInspectionController::class, 'approval'])->whereUuid('inspection');
});

Route::middleware('auth:sanctum')->prefix('inspections/panel-boxes')->group(function () {
    Route::get('/', [PanelBoxInspectionController::class, 'index']);
    Route::get('{inspection}', [PanelBoxInspectionController::class, 'show'])->whereUuid('inspection');
    Route::post('{inspection}/process', [PanelBoxInspectionController::class, 'process'])->whereUuid('inspection');
    Route::post('{inspection}/approval', [PanelBoxInspectionController::class, 'approval'])->whereUuid('inspection');
});

Route::middleware('auth:sanctum')->prefix('inspections/nvrs')->name('api.inspections.nvrs.')->group(function () {
    Route::get('/', [NvrInspectionController::class, 'index'])->name('index');
    Route::get('{inspection}', [NvrInspectionController::class, 'show'])->whereUuid('inspection')->name('show');
    Route::post('{inspection}/process', [NvrInspectionController::class, 'process'])->whereUuid('inspection')->name('process');
    Route::post('{inspection}/approval', [NvrInspectionController::class, 'approval'])->whereUuid('inspection')->name('approval');
});

Route::middleware('auth:sanctum')->prefix('inspections/cctv')->name('api.inspections.cctv.')->group(function () {
    Route::get('/', [CctvInspectionController::class, 'index'])->name('index');
    Route::get('{inspection}', [CctvInspectionController::class, 'show'])->whereUuid('inspection')->name('show');
    Route::post('{inspection}/process', [CctvInspectionController::class, 'process'])->whereUuid('inspection')->name('process');
    Route::post('{inspection}/approval', [CctvInspectionController::class, 'approval'])->whereUuid('inspection')->name('approval');
});

// Keep the generic inventory registry after every specialized inventory route.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/inventory/{type}/meta', [InventoryApiController::class, 'meta']);
    Route::get('/inventory/{type}/generate-code', [InventoryApiController::class, 'generateCode']);
    Route::get('/inventory/{type}', [InventoryApiController::class, 'index']);
    Route::post('/inventory/{type}', [InventoryApiController::class, 'store']);
    Route::get('/inventory/{type}/{id}', [InventoryApiController::class, 'show']);
    Route::match(['put', 'patch'], '/inventory/{type}/{id}', [InventoryApiController::class, 'update']);
    Route::delete('/inventory/{type}/{id}', [InventoryApiController::class, 'destroy']);
});

// Keep the generic inspection registry after every specialized inspection route.
// Otherwise values such as `nvrs` and `cctv` are consumed as `{type}` first.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/inspections/{type}', [InspectionApiController::class, 'index']);
    Route::post('/inspections/{type}/{id}/process', [InspectionApiController::class, 'process']);
    Route::post('/inspections/{type}/{id}/approval', [InspectionApiController::class, 'approval']);
    Route::get('/inspections/{type}/{id}', [InspectionApiController::class, 'show']);
    Route::match(['put', 'patch'], '/inspections/{type}/{id}', [InspectionApiController::class, 'update']);
});
