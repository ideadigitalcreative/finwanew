<?php

namespace App\Modules\RisenAI\Services;

use App\Modules\RisenAI\Models\SeoPage;

class IntentMatcherService
{
    public function __construct(
        private OpenRouterService $ai,
        private RagContentService $rag
    ) {}

    public function audit(SeoPage $page): array
    {
        $content = $page->content_json;

        $ctx = config('risen-ai.app_context');
        $knowledge = $this->rag->getContext($page->primary_keyword);

        $systemPrompt = "
Kamu adalah SEO Intent Auditor untuk {$ctx['full_name']}.
DATA KNOWLEDGE BASE:
{$knowledge}

Evaluasi apakah halaman ini sudah sesuai dengan intent pencarian user dan relevansi dengan fitur serta identitas {$ctx['name']}.
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
