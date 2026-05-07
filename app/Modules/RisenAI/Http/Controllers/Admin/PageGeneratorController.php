<?php

namespace App\Modules\RisenAI\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\RisenAI\Jobs\GeneratePageJob;
use App\Modules\RisenAI\Models\SeoPage;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PageGeneratorController extends Controller
{
    // Dashboard overview
    public function dashboard()
    {
        return Inertia::render('risen-ai/admin/Dashboard', [
            'stats' => [
                'total' => SeoPage::count(),
                'published' => SeoPage::where('status', 'published')->count(),
                'draft' => SeoPage::where('status', 'draft')->count(),
                'avg_score' => SeoPage::whereNotNull('intent_score')
                    ->avg('intent_score'),
            ],
            'recent_pages' => SeoPage::latest()->take(10)->get(),
        ]);
    }

    // List semua halaman
    public function index()
    {
        return Inertia::render('risen-ai/admin/PageGenerator', [
            'pages' => SeoPage::latest()->paginate(20),
            'clusters' => \App\Modules\RisenAI\Models\SeoKeywordCluster::all(), // Ambil data dari Data Lake
        ]);
    }

    // Trigger generate via queue
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'intent' => 'required|in:informational,transactional,comparison,navigational',
            'service' => 'required|string|max:100',
            'location' => 'nullable|string|max:100',
            'profession' => 'nullable|string|max:100',
            'cluster_id' => 'nullable|integer',
            'batch' => 'nullable|integer|max:50',
        ]);

        $batch = $validated['batch'] ?? 1;
        unset($validated['batch']);

        // Dispatch ke queue — tidak block request
        for ($i = 0; $i < $batch; $i++) {
            GeneratePageJob::dispatch($validated)
                ->onQueue(config('risen-ai.queue_name'));
        }

        return back()->with('success', "{$batch} halaman sedang digenerate di background.");
    }

    // Publish halaman
    public function publish(int $id)
    {
        SeoPage::findOrFail($id)->update(['status' => 'published']);

        return back()->with('success', 'Halaman berhasil dipublish.');
    }

    // Manual audit intent
    public function auditIntent($id, \App\Modules\RisenAI\Services\IntentAuditorService $service)
    {
        $page = SeoPage::findOrFail($id);
        $result = $service->audit($page);

        return back()->with('flash', ['result' => $result]);
    }

    public function update(Request $request, $id)
    {
        $page = SeoPage::findOrFail($id);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'h1' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'category' => 'nullable|string|max:255',
            'tags_raw' => 'nullable|string',
            'thumbnail_file' => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('thumbnail_file')) {
            $path = $request->file('thumbnail_file')->store('seo-thumbnails', 'public');
            $page->thumbnail = $path;
        }

        $page->title = $data['title'];
        $page->h1 = $data['h1'];
        $page->meta_description = $data['meta_description'];
        $page->category = $data['category'];

        if (isset($data['tags_raw'])) {
            $page->tags = array_map(fn ($t) => trim($t), explode(',', $data['tags_raw']));
        }

        $page->save();

        return back()->with('success', 'Halaman berhasil diperbarui.');
    }

    public function destroy($id)
    {
        $page = SeoPage::findOrFail($id);
        $page->delete();

        return back()->with('success', 'Halaman berhasil dihapus.');
    }
}
