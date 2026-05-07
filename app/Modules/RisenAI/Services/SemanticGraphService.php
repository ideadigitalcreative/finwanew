<?php

namespace App\Modules\RisenAI\Services;

class SemanticGraphService
{
    public function __construct(private OpenRouterService $ai) {}

    public function build(array $clusters, array $pillarTopics): array
    {
        $systemPrompt = '
Kamu adalah Semantic SEO Strategist untuk aplikasi keuangan Finwa Indonesia.
Bangun arsitektur topik yang kuat secara semantik.
Output HANYA JSON valid. Tidak ada teks di luar JSON.
        ';

        $clustersJson = json_encode($clusters, JSON_PRETTY_PRINT);
        $pillars = implode(', ', $pillarTopics);

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
