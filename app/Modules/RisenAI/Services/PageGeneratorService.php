<?php

namespace App\Modules\RisenAI\Services;

use App\Modules\RisenAI\Models\SeoPage;

class PageGeneratorService
{
    public function __construct(
        private OpenRouterService $ai,
        private RagContentService $rag
    ) {}

    public function generate(array $params): SeoPage
    {
        // $params: intent, service, location, profession, cluster_id
        // Jika lokasi adalah default 'Indonesia', buat kosong agar AI lebih bebas berkreasi di judul
        $locationCtx = ($params['location'] ?? null) ? "di {$params['location']}" : '';
        $professionCtx = ($params['profession'] ?? null) ? "untuk {$params['profession']}" : '';

        $ctx = config('risen-ai.app_context');
        $knowledge = $this->rag->getContext($params['service'], $params);

        $systemPrompt = "
Kamu adalah Ahli Programmatic Content & SEO Strategist untuk {$ctx['full_name']}.
TUGAS:
1. Tulis konten yang akurat berdasarkan DATA KNOWLEDGE BASE yang diberikan.
2. Gunakan gaya bahasa yang {$ctx['voice']}.
3. Fokus pada solusi praktis bagi pengguna (terutama UMKM dan personal).
4. Pastikan konten natural dalam Bahasa Indonesia.
5. Output HANYA JSON valid.
        ";

        $intentRules = match ($params['intent']) {
            'informational' => 'Fokus edukasi mendalam. JANGAN hard sell di paragraf pertama. Minimal 800 kata.',
            'transactional' => 'CTA kuat dalam 2 scroll pertama. Sertakan manfaat spesifik Finwa. Minimal 500 kata.',
            'comparison' => 'Wajib ada tabel perbandingan minimal 3 opsi. Sertakan rekomendasi kondisional. Minimal 700 kata.',
            default => 'Navigasi jelas ke fitur atau halaman utama Finwa.',
        };

        $userPrompt = "
{$knowledge}

TASK: Generate SEO Page untuk Finwa

VARIABEL:
- Layanan/Topik : {$params['service']}
- Lokasi        : ".($locationCtx ?: 'Umum (Indonesia)')."
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
- Judul (Title & H1): Harus menarik dan SEO-friendly. JANGAN memaksakan kata 'di Indonesia' jika tidak relevan atau membuat judul terasa kaku.
- Keyword utama muncul maksimal 3x secara natural
- Minimal 3 internal link. Prioritaskan link ke halaman fungsional: '/' (Home), '/register' (Daftar), '#harga' (Harga), '#fitur' (Fitur).
- FAQ minimal 3 pertanyaan relevan.
- URL slug: gunakan format [topik-slug]-[lokasi-slug] (TANPA prefix /artikel/).
- Meta description harus mengandung kata kerja aktif.
- FORMAT BODY: Wajib gunakan tag HTML murni (<p>, <strong>, <ul>, <li>). JANGAN gunakan markdown (**).
- Setiap paragraf wajib dipisah dengan tag <p>.
- TABEL PERBANDINGAN: Wajib dibungkus dengan <div class='table-wrapper'><table>...</table></div> agar responsif di mobile. Gunakan <thead> dan <tbody> untuk struktur yang rapi.
- Gunakan tag <th> untuk header kolom tabel.
        ";

        $result = $this->ai->complete($systemPrompt, $userPrompt, 5000);

        // Bersihkan slug: hapus semua variasi prefix /artikel/ yang mungkin diberikan AI
        $slug = $result['url_slug'] ?? ('artikel-'.\Illuminate\Support\Str::slug($params['service'].'-'.($params['location'] ?? '')));
        $slug = trim($slug, '/');
        $slug = preg_replace('#^/?artikel/#', '', $slug);
        $slug = trim($slug, '/');

        // Pastikan slug unik — tambah suffix angka jika sudah ada
        $baseSlug = $slug;
        $counter = 2;
        while (SeoPage::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        // Post-process: bersihkan body setiap section
        if (isset($result['content_sections']) && is_array($result['content_sections'])) {
            foreach ($result['content_sections'] as &$section) {
                if (isset($section['body'])) {
                    $section['body'] = $this->cleanBody($section['body']);
                }
                // Ganti internal links dengan link fungsional
                $section['internal_links'] = [
                    ['anchor' => 'Mulai Gunakan Finwa Gratis', 'url' => '/register'],
                    ['anchor' => 'Lihat Fitur Lengkap Finwa', 'url' => '/#fitur'],
                    ['anchor' => 'Bandingkan Paket Harga', 'url' => '/#harga'],
                ];
            }
        }

        // Simpan ke database sebagai draft
        return SeoPage::create([
            'slug' => $slug,
            'title' => $result['title'] ?? ($params['service'].' '.$locationCtx),
            'meta_description' => $result['meta_description'] ?? null,
            'h1' => $result['h1'] ?? ($result['title'] ?? null),
            'content_json' => $result,
            'primary_keyword' => $params['service'],
            'intent' => $params['intent'],
            'status' => 'draft',
            'service_var' => $params['service'],
            'location_var' => $params['location'] ?? null,
            'cluster_id' => $params['cluster_id'] ?? null,
            'schema_markup' => $result['schema_markup'] ?? null,
            'internal_links' => $result['content_sections'][0]['internal_links'] ?? null,
        ]);
    }

    /**
     * Konversi Markdown ke HTML dan pastikan paragraf terpisah dengan <p>.
     */
    private function cleanBody(string $body): string
    {
        // 1. Konversi **bold** ke <strong>
        $body = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $body);

        // 2. Konversi *italic* ke <em>
        $body = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $body);

        // 3. Jika body sudah mengandung <p>, kembalikan langsung (sudah HTML)
        if (str_contains($body, '<p>')) {
            return $body;
        }

        // 4. Konversi numbered list (1. ... 2. ... ) ke <ol><li>
        if (preg_match('/^\d+\.\s/m', $body)) {
            $body = preg_replace('/(\d+)\.\s+/', '', $body);
            // Split kalimat yang diawali angka
        }

        // 5. Pecah berdasarkan double newline atau kalimat panjang
        $paragraphs = preg_split('/\n\s*\n|\r\n\s*\r\n/', $body);

        // Jika hanya 1 paragraf besar, pecah berdasarkan titik (setiap 2-3 kalimat)
        if (count($paragraphs) <= 1) {
            $sentences = preg_split('/(?<=[.!?])\s+/', trim($body));
            $paragraphs = [];
            $current = '';
            $count = 0;
            foreach ($sentences as $sentence) {
                $current .= $sentence.' ';
                $count++;
                if ($count >= 3) {
                    $paragraphs[] = trim($current);
                    $current = '';
                    $count = 0;
                }
            }
            if (trim($current)) {
                $paragraphs[] = trim($current);
            }
        }

        // 6. Bungkus setiap paragraf dengan <p>
        $html = '';
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p) {
                $html .= "<p>{$p}</p>\n";
            }
        }

        return $html;
    }
}
