<?php

namespace App\Modules\RisenAI\Services;

class PerformanceMonitorService
{
    public function __construct(private OpenRouterService $ai) {}

    public function analyze(array $gscData, array $thresholds): array
    {
        $systemPrompt = '
Kamu adalah SEO Performance Analyst untuk Finwa.
Analisis data GSC dan rekomendasikan optimasi yang spesifik dan actionable.
Prioritaskan quick wins — dampak besar, mudah dikerjakan.
Output HANYA JSON valid. Tidak ada teks di luar JSON.
        ';

        $gscJson = json_encode($gscData, JSON_PRETTY_PRINT);
        $minCtr = $thresholds['min_ctr'] ?? 2;
        $posTarget = $thresholds['position_target'] ?? 20;

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
