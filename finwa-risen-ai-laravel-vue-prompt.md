# FINWA — Risen AI SEO System
## Implementation Prompt: Laravel + Vue (Non-Destructive)

> **Stack:** Laravel 10+ (Backend) + Vue 3 (Frontend)
> **Prinsip:** Zero perubahan pada struktur Finwa yang sudah ada
> **Pendekatan:** Tambah modul baru sepenuhnya terpisah (isolated module)
> **Output akhir:** Halaman SEO otomatis di `finwa.com/artikel/[slug]`

---

## MASTER PROMPT

Gunakan prompt ini sebagai brief lengkap kepada developer atau AI coding assistant (Cursor, Claude Code, dll).

```
Kamu adalah senior Laravel + Vue developer.
Kamu akan membangun modul SEO otomatis bernama "Risen AI"
di dalam aplikasi Finwa yang sudah berjalan.

CONSTRAINT UTAMA — WAJIB DIPATUHI:
1. JANGAN ubah file apapun yang sudah ada di Finwa
2. JANGAN ubah tabel database yang sudah ada
3. JANGAN ubah route yang sudah ada
4. Semua kode baru harus di folder/namespace terpisah
5. Gunakan Laravel Service Provider untuk register modul ini
6. Jika ada konflik, modul baru yang menyesuaikan — bukan sebaliknya

TECH STACK:
- Backend  : Laravel 10+
- Frontend : Vue 3 + Inertia.js (atau API mode jika Finwa pakai SPA)
- Database : MySQL (ikuti konvensi naming Finwa yang sudah ada)
- AI       : openrouter (dinamis bisa di ubah ubah modelnya)
- Queue    : Laravel Queue (gunakan queue yang sudah ada di Finwa)
- Cache    : Laravel Cache (gunakan driver yang sudah dipakai Finwa)
```

---

## BAGIAN 1 — STRUKTUR FOLDER

```
Prompt untuk developer:

Buat struktur folder berikut di dalam project Laravel Finwa.
Semua file modul SEO ada di dalam Modules/RisenAI/
sehingga tidak mengganggu struktur Finwa yang sudah ada.

app/
└── Modules/
    └── RisenAI/
        ├── Console/
        │   ├── GenerateDataLakeCommand.php
        │   ├── GenerateSemanticGraphCommand.php
        │   ├── GeneratePagesCommand.php
        │   └── RunPerformanceAuditCommand.php
        │
        ├── Http/
        │   ├── Controllers/
        │   │   ├── SeoPageController.php          ← render halaman publik
        │   │   └── Admin/
        │   │       ├── DataLakeController.php
        │   │       ├── SemanticGraphController.php
        │   │       ├── PageGeneratorController.php
        │   │       └── PerformanceController.php
        │   └── Requests/
        │       └── GeneratePageRequest.php
        │
        ├── Models/
        │   ├── SeoPage.php
        │   ├── SeoKeywordCluster.php
        │   ├── SeoTopicNode.php
        │   └── SeoPerformanceLog.php
        │
        ├── Services/
        │   ├── AnthropicService.php               ← wrapper Anthropic API
        │   ├── DataLakeService.php                ← MOD-01
        │   ├── SemanticGraphService.php           ← MOD-02
        │   ├── PageGeneratorService.php           ← MOD-03
        │   ├── RagContentService.php              ← MOD-04
        │   ├── IntentMatcherService.php           ← MOD-05
        │   └── PerformanceMonitorService.php      ← MOD-06
        │
        ├── Jobs/
        │   ├── GeneratePageJob.php
        │   ├── AuditIntentJob.php
        │   └── SyncGscDataJob.php
        │
        └── Providers/
            └── RisenAiServiceProvider.php         ← register semua di sini

database/
└── migrations/
    ├── 2025_01_01_000001_create_seo_pages_table.php
    ├── 2025_01_01_000002_create_seo_keyword_clusters_table.php
    ├── 2025_01_01_000003_create_seo_topic_nodes_table.php
    └── 2025_01_01_000004_create_seo_performance_logs_table.php

resources/js/
└── modules/
    └── risen-ai/
        ├── pages/
        │   ├── admin/
        │   │   ├── Dashboard.vue
        │   │   ├── DataLake.vue
        │   │   ├── PageGenerator.vue
        │   │   └── Performance.vue
        │   └── public/
        │       └── SeoArticle.vue                 ← template halaman publik
        └── components/
            ├── PageStatusBadge.vue
            ├── IntentScoreBar.vue
            └── GenerateButton.vue

routes/
├── web.php                                        ← JANGAN UBAH
└── risen-ai.php                                   ← file route BARU
```

---

## BAGIAN 2 — DATABASE MIGRATIONS

```
Prompt untuk developer:

Buat 4 migration baru. Jangan ubah migration yang sudah ada.
Gunakan prefix "seo_" untuk semua tabel baru agar tidak bentrok
dengan tabel Finwa yang sudah ada.
```

### Migration 1 — seo_pages

```php
Schema::create('seo_pages', function (Blueprint $table) {
    $table->id();
    $table->string('slug')->unique();
    $table->string('title');
    $table->string('meta_description', 160)->nullable();
    $table->string('h1')->nullable();
    $table->longText('content_json');
    $table->string('primary_keyword')->nullable();
    $table->enum('intent', [
        'informational',
        'transactional',
        'comparison',
        'navigational'
    ]);
    $table->enum('status', [
        'draft',
        'review',
        'published',
        'archived'
    ])->default('draft');
    $table->integer('intent_score')->nullable();
    $table->string('service_var')->nullable();
    $table->string('location_var')->nullable();
    $table->string('cluster_id')->nullable();
    $table->json('schema_markup')->nullable();
    $table->json('internal_links')->nullable();
    $table->timestamps();
    $table->softDeletes();
    $table->index(['status', 'intent']);
    $table->index('slug');
});
```

### Migration 2 — seo_keyword_clusters

```php
Schema::create('seo_keyword_clusters', function (Blueprint $table) {
    $table->id();
    $table->string('cluster_name');
    $table->string('primary_keyword');
    $table->json('secondary_keywords');
    $table->enum('avg_intent', [
        'informational',
        'transactional',
        'comparison',
        'navigational'
    ]);
    $table->enum('estimated_volume', ['low', 'medium', 'high'])
          ->default('medium');
    $table->string('niche')->nullable();
    $table->timestamps();
});
```

### Migration 3 — seo_topic_nodes

```php
Schema::create('seo_topic_nodes', function (Blueprint $table) {
    $table->id();
    $table->string('label');
    $table->enum('type', ['pillar', 'cluster', 'supporting']);
    $table->string('url_slug')->nullable();
    $table->json('connected_nodes')->nullable();
    $table->json('target_keywords')->nullable();
    $table->integer('semantic_score')->default(0);
    $table->timestamps();
});
```

### Migration 4 — seo_performance_logs

```php
Schema::create('seo_performance_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('seo_page_id')
          ->constrained('seo_pages')
          ->onDelete('cascade');
    $table->integer('impressions')->default(0);
    $table->integer('clicks')->default(0);
    $table->decimal('ctr', 5, 2)->default(0);
    $table->decimal('avg_position', 5, 1)->default(0);
    $table->enum('action_taken', [
        'none', 'title_test', 'rewrite', 'merge', 'deleted'
    ])->default('none');
    $table->date('recorded_date');
    $table->timestamps();
    $table->index(['seo_page_id', 'recorded_date']);
});
```

---

## BAGIAN 3 — SERVICE PROVIDER

```
Prompt untuk developer:

Buat RisenAiServiceProvider.php dan daftarkan di config/app.php
di bagian providers array — APPEND saja, jangan ganti yang lain.
```

```php
// app/Modules/RisenAI/Providers/RisenAiServiceProvider.php

namespace App\Modules\RisenAI\Providers;

use Illuminate\Support\ServiceProvider;

class RisenAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind semua services
        $this->app->singleton(AnthropicService::class);
        $this->app->singleton(DataLakeService::class);
        $this->app->singleton(SemanticGraphService::class);
        $this->app->singleton(PageGeneratorService::class);
        $this->app->singleton(RagContentService::class);
        $this->app->singleton(IntentMatcherService::class);
        $this->app->singleton(PerformanceMonitorService::class);

        // Load config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/risen-ai.php', 'risen-ai'
        );
    }

    public function boot(): void
    {
        // Load routes baru — tidak timpa routes Finwa
        $this->loadRoutesFrom(base_path('routes/risen-ai.php'));

        // Load migrations baru
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Register artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateDataLakeCommand::class,
                GenerateSemanticGraphCommand::class,
                GeneratePagesCommand::class,
                RunPerformanceAuditCommand::class,
            ]);
        }
    }
}
```

### Config File

```php
// config/risen-ai.php
// Tambah key berikut ke .env Finwa — jangan ubah .env key yang sudah ada

return [
    'anthropic_api_key' => env('RISEN_AI_ANTHROPIC_KEY'),
    'model'             => env('RISEN_AI_MODEL', 'claude-sonnet-4-20250514'),
    'max_tokens'        => env('RISEN_AI_MAX_TOKENS', 4000),
    'article_base_url'  => env('RISEN_AI_BASE_URL', '/artikel'),
    'auto_publish'      => env('RISEN_AI_AUTO_PUBLISH', false),
    'intent_min_score'  => env('RISEN_AI_INTENT_MIN_SCORE', 70),
    'queue_name'        => env('RISEN_AI_QUEUE', 'default'),
    'gsc_property_url'  => env('RISEN_AI_GSC_PROPERTY'),
];
```

---

## BAGIAN 4 — ROUTES (File Baru)

```
Prompt untuk developer:

Buat file routes/risen-ai.php
Lalu di routes/web.php yang sudah ada, tambahkan SATU baris
di paling bawah file (jangan ubah yang lain):

require base_path('routes/risen-ai.php');
```

```php
// routes/risen-ai.php

use App\Modules\RisenAI\Http\Controllers\SeoPageController;
use App\Modules\RisenAI\Http\Controllers\Admin\DataLakeController;
use App\Modules\RisenAI\Http\Controllers\Admin\PageGeneratorController;
use App\Modules\RisenAI\Http\Controllers\Admin\PerformanceController;

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

    // Performance Monitor (MOD-06)
    Route::get('/performance', [PerformanceController::class, 'index'])
        ->name('risen-ai.performance');
    Route::post('/performance/sync', [PerformanceController::class, 'syncGsc'])
        ->name('risen-ai.performance.sync');
});
```

---

## BAGIAN 5 — ANTHROPIC SERVICE (Core Wrapper)

```
Prompt untuk developer:

Ini adalah wrapper utama untuk semua panggilan ke Anthropic API.
Semua modul (MOD-01 sampai MOD-06) menggunakan service ini.
Jangan hardcode API key — ambil dari config risen-ai.php.
```

```php
// app/Modules/RisenAI/Services/AnthropicService.php

namespace App\Modules\RisenAI\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnthropicService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct()
    {
        $this->apiKey = config('risen-ai.anthropic_api_key');
        $this->model  = config('risen-ai.model');
    }

    /**
     * Kirim prompt ke Claude, return array hasil parsing JSON.
     * Dipakai oleh semua modul MOD-01 sampai MOD-06.
     */
    public function complete(
        string $systemPrompt,
        string $userPrompt,
        int $maxTokens = 4000
    ): array {
        try {
            $response = Http::withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ])->timeout(120)->post($this->baseUrl, [
                'model'      => $this->model,
                'max_tokens' => $maxTokens,
                'system'     => $systemPrompt,
                'messages'   => [
                    ['role' => 'user', 'content' => $userPrompt]
                ],
            ]);

            if ($response->failed()) {
                Log::error('RisenAI: Anthropic API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                throw new \Exception(
                    'Anthropic API gagal: ' . $response->status()
                );
            }

            $content = $response->json('content.0.text');

            // Bersihkan markdown code fences jika ada
            $content = preg_replace('/```json\s*|\s*```/', '', $content);
            $content = trim($content);

            return json_decode($content, true) ?? [];

        } catch (\Exception $e) {
            Log::error('RisenAI: AnthropicService error', [
                'message' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
```

---

## BAGIAN 6 — AI PROMPT PER MODUL (Services)

### MOD-01: DataLakeService

```php
// app/Modules/RisenAI/Services/DataLakeService.php

namespace App\Modules\RisenAI\Services;

use App\Modules\RisenAI\Models\SeoKeywordCluster;

class DataLakeService
{
    public function __construct(private AnthropicService $ai) {}

    public function build(array $input): array
    {
        $systemPrompt = "
Kamu adalah data engineer AI untuk sistem Programmatic SEO
aplikasi keuangan Finwa Indonesia.
Tugas kamu membangun data lake terstruktur dari input yang diberikan.
Output HANYA JSON valid. Tidak ada teks di luar JSON.
        ";

        $seeds      = implode(', ', $input['seed_keywords']);
        $locations  = implode(', ', $input['locations']);
        $professions = implode(', ', $input['professions'] ?? []);

        $userPrompt = "
TASK: Build Data Lake untuk Finwa

INPUT:
- Niche: Aplikasi keuangan personal dan UMKM Indonesia
- Seed Keywords: {$seeds}
- Kota Target: {$locations}
- Profesi Target: {$professions}

OUTPUT FORMAT (JSON):
{
  \"keyword_clusters\": [
    {
      \"cluster_name\": \"...\",
      \"primary_keyword\": \"...\",
      \"secondary_keywords\": [\"...\", \"...\"],
      \"avg_intent\": \"informational|transactional|comparison|navigational\",
      \"estimated_volume\": \"low|medium|high\",
      \"finwa_relevance\": \"personal|umkm|both\"
    }
  ],
  \"intent_map\": {
    \"informational\": [],
    \"transactional\": [],
    \"navigational\": [],
    \"comparison\": []
  },
  \"topic_graph\": {
    \"nodes\": [{ \"id\": \"node_1\", \"label\": \"...\", \"type\": \"pillar|cluster|supporting\" }],
    \"edges\": [{ \"from\": \"node_1\", \"to\": \"node_2\", \"relation\": \"parent-child|related\" }]
  },
  \"location_variations\": [
    { \"base_keyword\": \"...\", \"location\": \"...\", \"variation\": \"...\", \"intent\": \"...\" }
  ]
}

RULES:
- Fokus konteks keuangan Indonesia: UMR, UMKM, warung, ojol, freelancer, dll
- Setiap cluster minimal 3 secondary keywords
- Hasilkan variasi lokasi untuk semua kota yang diberikan
- Maksimal 15 clusters per request
        ";

        $result = $this->ai->complete($systemPrompt, $userPrompt, 6000);

        // Simpan ke database
        foreach ($result['keyword_clusters'] ?? [] as $cluster) {
            SeoKeywordCluster::updateOrCreate(
                ['primary_keyword' => $cluster['primary_keyword']],
                [
                    'cluster_name'       => $cluster['cluster_name'],
                    'secondary_keywords' => $cluster['secondary_keywords'],
                    'avg_intent'         => $cluster['avg_intent'],
                    'estimated_volume'   => $cluster['estimated_volume'],
                    'niche'              => 'finwa-' . ($cluster['finwa_relevance'] ?? 'both'),
                ]
            );
        }

        return $result;
    }
}
```

---

### MOD-02: SemanticGraphService

```php
// app/Modules/RisenAI/Services/SemanticGraphService.php

class SemanticGraphService
{
    public function __construct(private AnthropicService $ai) {}

    public function build(array $clusters, array $pillarTopics): array
    {
        $systemPrompt = "
Kamu adalah Semantic SEO Strategist untuk aplikasi keuangan Finwa Indonesia.
Bangun arsitektur topik yang kuat secara semantik.
Output HANYA JSON valid. Tidak ada teks di luar JSON.
        ";

        $clustersJson = json_encode($clusters, JSON_PRETTY_PRINT);
        $pillars      = implode(', ', $pillarTopics);

        $userPrompt = "
TASK: Build Semantic Topic Graph untuk Finwa

INPUT:
- keyword_clusters: {$clustersJson}
- Pillar Topics yang diinginkan: {$pillars}

OUTPUT FORMAT (JSON):
{
  \"pillar_pages\": [
    {
      \"topic\": \"...\",
      \"url_slug\": \"/artikel/topik-utama\",
      \"meta_title\": \"...\",
      \"target_keywords\": [\"...\"],
      \"child_pages\": [
        {
          \"topic\": \"...\",
          \"url_slug\": \"/artikel/topik-utama/sub-topik\",
          \"intent\": \"informational|transactional|comparison\",
          \"target_keyword\": \"...\"
        }
      ]
    }
  ],
  \"internal_link_map\": [
    {
      \"from_page\": \"/artikel/asal\",
      \"to_pages\": [\"/artikel/tujuan-1\"],
      \"anchor_texts\": [\"teks anchor natural\"]
    }
  ],
  \"semantic_score\": 0,
  \"coverage_gaps\": [\"topik yang belum tercakup\"]
}

RULES:
- Setiap pillar page minimal 5 child pages
- Hindari keyword cannibalization
- Anchor text harus natural dan bervariasi
- Semua URL dimulai dengan /artikel/
        ";

        return $this->ai->complete($systemPrompt, $userPrompt, 5000);
    }
}
```

---

### MOD-03 + MOD-04: PageGeneratorService

```php
// app/Modules/RisenAI/Services/PageGeneratorService.php

class PageGeneratorService
{
    public function __construct(private AnthropicService $ai) {}

    public function generate(array $params): SeoPage
    {
        // $params: intent, service, location, profession, cluster_id
        $locationCtx   = $params['location']   ? "di {$params['location']}" : "di Indonesia";
        $professionCtx = $params['profession'] ? "untuk {$params['profession']}" : "";

        $systemPrompt = "
Kamu adalah Programmatic Content Generator untuk aplikasi keuangan Finwa Indonesia.
Brand voice Finwa: profesional tapi hangat, mudah dipahami, fokus solusi praktis.
Tulis konten natural dalam Bahasa Indonesia yang baik.
Output HANYA JSON valid. Tidak ada teks di luar JSON.
        ";

        $intentRules = match($params['intent']) {
            'informational' => "Fokus edukasi mendalam. JANGAN hard sell di paragraf pertama. Minimal 800 kata.",
            'transactional' => "CTA kuat dalam 2 scroll pertama. Sertakan manfaat spesifik Finwa. Minimal 500 kata.",
            'comparison'    => "Wajib ada tabel perbandingan minimal 3 opsi. Sertakan rekomendasi kondisional. Minimal 700 kata.",
            default         => "Navigasi jelas ke fitur atau halaman utama Finwa.",
        };

        $userPrompt = "
TASK: Generate SEO Page untuk Finwa

VARIABEL:
- Layanan/Topik : {$params['service']}
- Lokasi        : {$locationCtx}
- Profesi       : {$professionCtx}
- Intent        : {$params['intent']}

ATURAN INTENT:
{$intentRules}

OUTPUT FORMAT (JSON):
{
  \"url_slug\": \"...\",
  \"title\": \"...\",
  \"meta_description\": \"... (maks 160 karakter)\",
  \"h1\": \"...\",
  \"content_sections\": [
    {
      \"heading\": \"H2 ...\",
      \"body\": \"2-3 paragraf konten...\",
      \"internal_links\": [
        { \"anchor\": \"teks anchor\", \"url\": \"/artikel/...\" }
      ]
    }
  ],
  \"faq\": [
    { \"question\": \"...\", \"answer\": \"...\" }
  ],
  \"cta\": {
    \"headline\": \"...\",
    \"subtext\": \"...\",
    \"button_text\": \"Coba Finwa Gratis\"
  },
  \"schema_markup\": {
    \"@context\": \"https://schema.org\",
    \"@type\": \"Article\",
    \"headline\": \"...\"
  }
}

RULES WAJIB:
- Konten harus 100% unik, tidak copy-paste template
- Keyword utama muncul maksimal 3x secara natural
- Minimal 3 internal link ke halaman Finwa lain (/artikel/...)
- FAQ minimal 3 pertanyaan relevan
- URL slug: gunakan format /artikel/[topik-slug]-[lokasi-slug]
- Meta description harus mengandung kata kerja aktif
        ";

        $result = $this->ai->complete($systemPrompt, $userPrompt, 5000);

        // Simpan ke database sebagai draft
        return SeoPage::create([
            'slug'            => $result['url_slug'],
            'title'           => $result['title'],
            'meta_description' => $result['meta_description'],
            'h1'              => $result['h1'],
            'content_json'    => json_encode($result),
            'primary_keyword' => $params['service'],
            'intent'          => $params['intent'],
            'status'          => 'draft',
            'service_var'     => $params['service'],
            'location_var'    => $params['location'] ?? null,
            'cluster_id'      => $params['cluster_id'] ?? null,
            'schema_markup'   => $result['schema_markup'] ?? null,
            'internal_links'  => $result['content_sections'][0]['internal_links'] ?? null,
        ]);
    }
}
```

---

### MOD-05: IntentMatcherService

```php
// app/Modules/RisenAI/Services/IntentMatcherService.php

class IntentMatcherService
{
    public function __construct(private AnthropicService $ai) {}

    public function audit(SeoPage $page): array
    {
        $content = json_decode($page->content_json, true);

        $systemPrompt = "
Kamu adalah SEO Intent Auditor untuk Finwa.
Evaluasi apakah halaman ini sudah sesuai dengan intent pencarian user.
Output HANYA JSON valid. Tidak ada teks di luar JSON.
        ";

        $contentStr = json_encode($content, JSON_PRETTY_PRINT);

        $userPrompt = "
TASK: Audit Intent Match

INPUT:
- URL      : {$page->slug}
- Intent   : {$page->intent}
- Keyword  : {$page->primary_keyword}
- Konten   : {$contentStr}

OUTPUT FORMAT (JSON):
{
  \"intent_match_score\": 0,
  \"verdict\": \"pass|revise|reject\",
  \"score_breakdown\": {
    \"content_depth\": 0,
    \"cta_placement\": 0,
    \"structural_elements\": 0,
    \"semantic_alignment\": 0
  },
  \"issues\": [
    {
      \"issue\": \"...\",
      \"severity\": \"critical|major|minor\"
    }
  ],
  \"recommendations\": [\"...\"],
  \"serp_features_to_target\": [\"featured snippet\", \"people also ask\"],
  \"missing_elements\": [\"...\"]
}

SCORING:
- Score < 50  → reject  (buat ulang dari awal)
- Score 50-69 → revise  (perbaiki issues critical)
- Score >= 70 → pass    (boleh publish)
        ";

        $result = $this->ai->complete($systemPrompt, $userPrompt, 2000);

        // Update score di database
        $page->update(['intent_score' => $result['intent_match_score'] ?? 0]);

        return $result;
    }
}
```

---

### MOD-06: PerformanceMonitorService

```php
// app/Modules/RisenAI/Services/PerformanceMonitorService.php

class PerformanceMonitorService
{
    public function __construct(private AnthropicService $ai) {}

    public function analyze(array $gscData, array $thresholds): array
    {
        $systemPrompt = "
Kamu adalah SEO Performance Analyst untuk Finwa.
Analisis data GSC dan rekomendasikan optimasi yang spesifik dan actionable.
Prioritaskan quick wins — dampak besar, mudah dikerjakan.
Output HANYA JSON valid. Tidak ada teks di luar JSON.
        ";

        $gscJson     = json_encode($gscData, JSON_PRETTY_PRINT);
        $minCtr      = $thresholds['min_ctr'] ?? 2;
        $posTarget   = $thresholds['position_target'] ?? 20;

        $userPrompt = "
TASK: Analisis Performa Halaman Finwa

GSC DATA:
{$gscJson}

THRESHOLD:
- Minimum CTR     : {$minCtr}%
- Target Position : {$posTarget}

OUTPUT FORMAT (JSON):
{
  \"summary\": {
    \"total_pages\": 0,
    \"passing\": 0,
    \"needs_attention\": 0,
    \"critical\": 0
  },
  \"top_performers\": [
    {
      \"url\": \"...\",
      \"why\": \"...\",
      \"replicate_pattern\": \"...\"
    }
  ],
  \"underperformers\": [
    {
      \"url\": \"...\",
      \"issue\": \"...\",
      \"root_cause\": \"indexing|intent_mismatch|thin_content|title_issue\",
      \"action\": \"rewrite|restructure|delete|merge|title_test\",
      \"priority\": \"high|medium|low\"
    }
  ],
  \"quick_wins\": [
    {
      \"url\": \"...\",
      \"action\": \"...\",
      \"expected_impact\": \"...\"
    }
  ],
  \"title_ab_tests\": [
    {
      \"url\": \"...\",
      \"current_title\": \"...\",
      \"variant_a\": \"...\",
      \"variant_b\": \"...\"
    }
  ],
  \"regeneration_queue\": [
    {
      \"url\": \"...\",
      \"trigger_module\": \"MOD-03|MOD-04\",
      \"reason\": \"...\"
    }
  ]
}

RULES:
- CTR < threshold         → wajib masuk title_ab_tests
- Position > 20           → masuk quick_wins untuk content expansion
- Impressi = 0 > 30 hari  → audit indexing
- Impressi tinggi CTR < 1% → highest priority quick win
- Minimal 3 quick wins per analisis
        ";

        return $this->ai->complete($systemPrompt, $userPrompt, 4000);
    }
}
```

---

## BAGIAN 7 — QUEUE JOBS

```
Prompt untuk developer:

Buat Job classes agar generate halaman berjalan
di background queue — tidak memblokir request HTTP Finwa.
```

```php
// app/Modules/RisenAI/Jobs/GeneratePageJob.php

namespace App\Modules\RisenAI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use App\Modules\RisenAI\Services\PageGeneratorService;
use App\Modules\RisenAI\Services\IntentMatcherService;

class GeneratePageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries   = 3;
    public int $timeout = 180;

    public function __construct(private array $params) {}

    public function handle(
        PageGeneratorService $generator,
        IntentMatcherService $matcher
    ): void {
        // 1. Generate halaman
        $page = $generator->generate($this->params);

        // 2. Langsung audit intent
        $audit = $matcher->audit($page);

        // 3. Auto publish jika score >= threshold dan config allow
        $minScore = config('risen-ai.intent_min_score', 70);
        $autoPublish = config('risen-ai.auto_publish', false);

        if ($autoPublish && $audit['intent_match_score'] >= $minScore) {
            $page->update(['status' => 'published']);
        }
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('RisenAI: GeneratePageJob failed', [
            'params'  => $this->params,
            'message' => $exception->getMessage(),
        ]);
    }
}
```

---

## BAGIAN 8 — CONTROLLER ADMIN

```
Prompt untuk developer:

Buat controller untuk admin panel.
Gunakan middleware auth yang sudah ada di Finwa — jangan buat middleware baru.
```

```php
// app/Modules/RisenAI/Http/Controllers/Admin/PageGeneratorController.php

namespace App\Modules\RisenAI\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\RisenAI\Models\SeoPage;
use App\Modules\RisenAI\Jobs\GeneratePageJob;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PageGeneratorController extends Controller
{
    // Dashboard overview
    public function dashboard()
    {
        return Inertia::render('risen-ai/pages/admin/Dashboard', [
            'stats' => [
                'total'     => SeoPage::count(),
                'published' => SeoPage::where('status', 'published')->count(),
                'draft'     => SeoPage::where('status', 'draft')->count(),
                'avg_score' => SeoPage::whereNotNull('intent_score')
                                      ->avg('intent_score'),
            ],
            'recent_pages' => SeoPage::latest()->take(10)->get(),
        ]);
    }

    // List semua halaman
    public function index()
    {
        return Inertia::render('risen-ai/pages/admin/PageGenerator', [
            'pages' => SeoPage::latest()->paginate(20),
        ]);
    }

    // Trigger generate via queue
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'intent'     => 'required|in:informational,transactional,comparison,navigational',
            'service'    => 'required|string|max:100',
            'location'   => 'nullable|string|max:100',
            'profession' => 'nullable|string|max:100',
            'cluster_id' => 'nullable|integer',
            'batch'      => 'nullable|integer|max:50',
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
    public function auditIntent(int $id, IntentMatcherService $matcher)
    {
        $page  = SeoPage::findOrFail($id);
        $audit = $matcher->audit($page);
        return response()->json($audit);
    }
}
```

---

## BAGIAN 9 — PUBLIC CONTROLLER (Render Halaman SEO)

```php
// app/Modules/RisenAI/Http/Controllers/SeoPageController.php

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

        $content = json_decode($page->content_json, true);

        return Inertia::render('risen-ai/pages/public/SeoArticle', [
            'page'    => $page,
            'content' => $content,
        ]);
    }

    public function index()
    {
        $pages = SeoPage::where('status', 'published')
                         ->select(['id', 'slug', 'title', 'meta_description', 'intent', 'created_at'])
                         ->latest()
                         ->paginate(24);

        return Inertia::render('risen-ai/pages/public/SeoArticle', [
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
```

---

## BAGIAN 10 — VUE COMPONENT (Template Halaman Publik)

```
Prompt untuk developer:

Buat Vue component untuk render halaman SEO publik.
Sesuaikan styling dengan design system Finwa yang sudah ada.
JANGAN buat CSS baru yang konflik — extend saja yang sudah ada.
```

```vue
<!-- resources/js/modules/risen-ai/pages/public/SeoArticle.vue -->

<template>
  <div class="seo-article">

    <!-- Head (gunakan plugin Head Inertia/Vue yang sudah dipakai Finwa) -->
    <Head>
      <title>{{ page.title }}</title>
      <meta name="description" :content="page.meta_description" />
      <link rel="canonical" :href="canonicalUrl" />
      <script type="application/ld+json" v-if="page.schema_markup">
        {{ JSON.stringify(page.schema_markup) }}
      </script>
    </Head>

    <!-- Hero Section -->
    <header class="article-hero">
      <div class="container">
        <span class="intent-badge" :class="page.intent">
          {{ intentLabel }}
        </span>
        <h1>{{ content.h1 || page.title }}</h1>
        <p class="meta">
          Diperbarui {{ formatDate(page.updated_at) }}
        </p>
      </div>
    </header>

    <!-- Main Content -->
    <main class="article-body container">

      <!-- Intro Paragraph -->
      <p class="intro" v-if="content.intro_paragraph">
        {{ content.intro_paragraph }}
      </p>

      <!-- Content Sections -->
      <section
        v-for="(section, i) in content.content_sections"
        :key="i"
        class="article-section"
      >
        <h2>{{ section.heading }}</h2>
        <div v-html="section.body"></div>
      </section>

      <!-- FAQ Section -->
      <section class="faq-section" v-if="content.faq?.length">
        <h2>Pertanyaan yang Sering Ditanyakan</h2>
        <details
          v-for="(item, i) in content.faq"
          :key="i"
          class="faq-item"
        >
          <summary>{{ item.question }}</summary>
          <p>{{ item.answer }}</p>
        </details>
      </section>

    </main>

    <!-- CTA Section -->
    <aside class="article-cta" v-if="content.cta">
      <div class="container">
        <h3>{{ content.cta.headline }}</h3>
        <p>{{ content.cta.subtext }}</p>
        <a href="/daftar" class="btn btn-primary">
          {{ content.cta.button_text }}
        </a>
      </div>
    </aside>

  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Head } from '@inertiajs/vue3'

const props = defineProps({
  page: Object,
  content: Object,
})

const canonicalUrl = computed(() =>
  `${window.location.origin}/artikel/${props.page.slug}`
)

const intentLabel = computed(() => ({
  informational : 'Panduan',
  transactional : 'Download',
  comparison    : 'Perbandingan',
  navigational  : 'Navigasi',
}[props.page.intent] || 'Artikel'))

const formatDate = (date) =>
  new Date(date).toLocaleDateString('id-ID', {
    year: 'month', month: 'long', day: 'numeric'
  })
</script>
```

---

## BAGIAN 11 — ARTISAN COMMANDS

```
Prompt untuk developer:

Buat Artisan commands agar sistem bisa dijalankan
via CLI atau Laravel Scheduler.
```

```php
// Contoh penggunaan setelah semua command dibuat:

// Generate data lake dari seed keywords
php artisan risen-ai:data-lake \
  --keywords="aplikasi keuangan,catat pengeluaran,menabung" \
  --locations="Jakarta,Surabaya,Makassar,Bandung,Medan"

// Generate 10 halaman informational untuk personal finance
php artisan risen-ai:generate-pages \
  --intent=informational \
  --service="mengatur keuangan" \
  --batch=10

// Generate halaman per kota (programmatic)
php artisan risen-ai:generate-pages \
  --intent=transactional \
  --service="aplikasi keuangan" \
  --locations="Jakarta,Surabaya,Makassar" \
  --batch=1

// Jalankan audit performance
php artisan risen-ai:performance-audit

// Tambahkan ke Laravel Scheduler di app/Console/Kernel.php
// (tambah di method schedule(), jangan hapus yang sudah ada)
$schedule->command('risen-ai:performance-audit')->weekly();
```

---

## BAGIAN 12 — CHECKLIST IMPLEMENTASI

```
Prompt untuk developer — kerjakan berurutan:

FASE 1: Setup (Hari 1-2)
[ ] Buat folder Modules/RisenAI/ beserta subfolder
[ ] Buat dan jalankan 4 migrations baru
[ ] Daftarkan RisenAiServiceProvider di config/app.php
[ ] Tambahkan RISEN_AI_ANTHROPIC_KEY ke .env
[ ] Test koneksi ke Anthropic API

FASE 2: Backend Core (Hari 3-5)
[ ] Buat AnthropicService.php dan test dengan prompt sederhana
[ ] Buat DataLakeService.php (MOD-01)
[ ] Buat SemanticGraphService.php (MOD-02)
[ ] Buat PageGeneratorService.php (MOD-03+04)
[ ] Buat IntentMatcherService.php (MOD-05)
[ ] Buat PerformanceMonitorService.php (MOD-06)
[ ] Buat GeneratePageJob.php

FASE 3: Routes & Controllers (Hari 6-7)
[ ] Buat routes/risen-ai.php
[ ] Tambahkan require risen-ai.php di routes/web.php (1 baris)
[ ] Buat SeoPageController.php (public)
[ ] Buat PageGeneratorController.php (admin)

FASE 4: Frontend Vue (Hari 8-10)
[ ] Buat SeoArticle.vue (template halaman publik)
[ ] Buat Dashboard.vue (admin overview)
[ ] Buat PageGenerator.vue (admin generate)
[ ] Daftarkan routes Vue/Inertia

FASE 5: Testing (Hari 11-14)
[ ] Generate 5 halaman test via Artisan command
[ ] Review output di admin dashboard
[ ] Publish 1 halaman, cek render di /artikel/[slug]
[ ] Submit ke Google Search Console
[ ] Verifikasi tidak ada regresi di fitur Finwa yang sudah ada

FASE 6: Go Live
[ ] Generate batch pertama: 50 halaman personal finance
[ ] Generate batch kedua: halaman per kota tier 1 (10 kota × 3 intent)
[ ] Setup scheduler untuk performance audit mingguan
```

---

## CATATAN PENTING

> **Zero Regression**: Selalu jalankan test suite Finwa yang ada setelah setiap fase selesai. Modul ini tidak boleh menyebabkan error pada fitur Finwa yang sudah berjalan.

> **API Cost**: Setiap generate halaman = 1 API call ke Anthropic (~$0.003-0.01 per halaman). Generate batch via queue di luar jam sibuk untuk efisiensi cost.

> **Auto Publish**: Set `RISEN_AI_AUTO_PUBLISH=false` di awal. Review manual dulu sampai kamu yakin dengan kualitas output, baru aktifkan auto publish.

> **Queue Worker**: Pastikan Laravel queue worker berjalan (`php artisan queue:work`). Jika Finwa sudah pakai queue (misal untuk notifikasi), modul ini ikut queue yang sama.

---

*Risen AI for Finwa — Implementation Prompt v1.0*
*Laravel + Vue · Non-Destructive Module · Isolated Namespace*
