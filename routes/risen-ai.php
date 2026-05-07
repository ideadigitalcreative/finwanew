<?php

use App\Modules\RisenAI\Http\Controllers\Admin\DataLakeController;
use App\Modules\RisenAI\Http\Controllers\Admin\PageGeneratorController;
use App\Modules\RisenAI\Http\Controllers\Admin\PerformanceController;
use App\Modules\RisenAI\Http\Controllers\SeoPageController;
use Illuminate\Support\Facades\Route;

// ─── PUBLIC ROUTES ───────────────────────────────────────────────
// Halaman artikel SEO yang diindex Google
Route::get('/artikel/{slug}', [SeoPageController::class, 'show'])
    ->name('seo.article.show');

Route::get('/artikel', [SeoPageController::class, 'index'])
    ->name('seo.article.index');

// Sitemap untuk modul ini
Route::get('/sitemap-seo.xml', [SeoPageController::class, 'sitemap'])
    ->name('seo.sitemap');

// ─── ADMIN ROUTES ─────────────────────────────────────────────────
// Pakai middleware auth yang sudah ada di Finwa
Route::middleware(['web', 'auth'])->prefix('admin/risen-ai')->group(function () {

    // Dashboard overview
    Route::get('/', [PageGeneratorController::class, 'dashboard'])
        ->name('risen-ai.dashboard');

    // Data Lake (MOD-01)
    Route::get('/data-lake', [DataLakeController::class, 'index'])
        ->name('risen-ai.data-lake');
    Route::post('/data-lake/generate', [DataLakeController::class, 'generate'])
        ->name('risen-ai.data-lake.generate');

    // Page Generator (MOD-03 + MOD-04)
    Route::get('/pages', [PageGeneratorController::class, 'index'])
        ->name('risen-ai.pages');
    Route::post('/pages/generate', [PageGeneratorController::class, 'generate'])
        ->name('risen-ai.pages.generate');
    Route::post('/pages/{id}/publish', [PageGeneratorController::class, 'publish'])
        ->name('risen-ai.pages.publish');
    Route::post('/pages/{id}/audit', [PageGeneratorController::class, 'auditIntent'])
        ->name('risen-ai.pages.audit');
    Route::put('/pages/{id}', [PageGeneratorController::class, 'update'])
        ->name('risen-ai.pages.update');
    Route::delete('/pages/{id}', [PageGeneratorController::class, 'destroy'])
        ->name('risen-ai.pages.destroy');

    // Performance Monitor (MOD-06)
    Route::get('/performance', [PerformanceController::class, 'index'])
        ->name('risen-ai.performance');
    Route::post('/performance/sync', [PerformanceController::class, 'syncGsc'])
        ->name('risen-ai.performance.sync');
});
