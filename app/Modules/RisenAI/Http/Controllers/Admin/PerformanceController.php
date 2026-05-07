<?php

namespace App\Modules\RisenAI\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\RisenAI\Models\SeoPerformanceLog;
use App\Modules\RisenAI\Services\PerformanceMonitorService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PerformanceController extends Controller
{
    public function index()
    {
        return Inertia::render('risen-ai/admin/Performance', [
            'logs' => SeoPerformanceLog::with('page')->latest()->paginate(50),
        ]);
    }

    public function syncGsc(Request $request, PerformanceMonitorService $service)
    {
        // Implementasi sinkronisasi GSC akan ditambahkan di sini
        return back()->with('success', 'Sinkronisasi GSC sedang diproses.');
    }
}
