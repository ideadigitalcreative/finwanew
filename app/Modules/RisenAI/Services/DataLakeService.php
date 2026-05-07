<?php

namespace App\Modules\RisenAI\Services;

use App\Modules\RisenAI\Models\SeoKeywordCluster;

class DataLakeService
{
    public function __construct(private OpenRouterService $ai) {}

    public function build(array $input): array
    {
        $systemPrompt = '
Kamu adalah data engineer AI untuk sistem Programmatic SEO
aplikasi keuangan Finwa Indonesia.
Tugas kamu membangun data lake terstruktur dari input yang diberikan.
Output HANYA JSON valid. Tidak ada teks di luar JSON.
        ';

        $seeds = implode(', ', $input['seed_keywords'] ?? []);
        $locations = implode(', ', $input['locations'] ?? []);
        $professions = implode(', ', $input['professions'] ?? []);

        $ctx = config('risen-ai.app_context');
        $userPrompt = "
TASK: Build Data Lake untuk {$ctx['name']}

INPUT:
- Niche: {$ctx['description']}
- Target: {$ctx['target_users']}
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
                    'cluster_name' => $cluster['cluster_name'],
                    'secondary_keywords' => $cluster['secondary_keywords'],
                    'avg_intent' => $cluster['avg_intent'],
                    'estimated_volume' => $cluster['estimated_volume'],
                    'niche' => 'finwa-'.($cluster['finwa_relevance'] ?? 'both'),
                ]
            );
        }

        return $result;
    }
}
