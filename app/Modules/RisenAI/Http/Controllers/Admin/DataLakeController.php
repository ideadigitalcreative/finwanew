<?php

namespace App\Modules\RisenAI\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\RisenAI\Models\SeoKeywordCluster;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DataLakeController extends Controller
{
    public function index()
    {
        return Inertia::render('risen-ai/admin/DataLake', [
            'clusters' => SeoKeywordCluster::latest()->paginate(20),
        ]);
    }

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'seed_keywords' => 'required|array',
            'locations' => 'required|array',
            'professions' => 'nullable|array',
        ]);

        \App\Modules\RisenAI\Jobs\BuildDataLakeJob::dispatch($validated);

        return back()->with([
            'success' => 'Permintaan riset sedang diproses di latar belakang. Data akan muncul otomatis setelah selesai.',
        ]);
    }
}
