<?php

namespace App\Modules\RisenAI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\RisenAI\Models\SeoPage;
use Inertia\Inertia;

class SeoPageController extends Controller
{
    public function show(string $slug)
    {
        $page = SeoPage::where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        $content = $page->content_json;

        return Inertia::render('risen-ai/public/SeoArticle', [
            'page' => $page,
            'content' => $page->content_json,
        ])->withViewData(['seo_page' => $page]);
    }

    public function index()
    {
        $pages = SeoPage::where('status', 'published')
            ->select(['id', 'slug', 'title', 'meta_description', 'intent', 'created_at'])
            ->latest()
            ->paginate(24);

        return Inertia::render('risen-ai/public/SeoArticleList', [
            'pages' => $pages,
        ]);
    }

    public function sitemap()
    {
        $pages = SeoPage::where('status', 'published')
            ->select(['slug', 'updated_at'])
            ->get();

        return response()
            ->view('risen-ai::sitemap', ['pages' => $pages])
            ->header('Content-Type', 'text/xml');
    }
}
