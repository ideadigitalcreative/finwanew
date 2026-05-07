<?php

namespace App\Services\Category;

use App\Models\CategoryCorrection;
use Illuminate\Support\Facades\Log;

class CategoryCorrectionService
{
    public function recordCorrection(
        int $tenantId,
        string $originalText,
        string $originalCategory,
        string $correctedCategory,
        ?string $merchant = null,
        ?float $amount = null
    ): void {
        try {
            $normalizedText = mb_strtolower(trim($originalText));
            $normalizedText = preg_replace('/\s+/', ' ', $normalizedText);

            $existing = CategoryCorrection::where('tenant_id', $tenantId)
                ->where('original_text', $normalizedText)
                ->where('original_category', $originalCategory)
                ->where('corrected_category', $correctedCategory)
                ->first();

            if ($existing) {
                $existing->increment('frequency');

                return;
            }

            CategoryCorrection::create([
                'tenant_id' => $tenantId,
                'original_text' => mb_substr($normalizedText, 0, 500),
                'original_category' => $originalCategory,
                'corrected_category' => $correctedCategory,
                'merchant' => $merchant ? mb_strtolower(trim($merchant)) : null,
                'amount' => $amount,
                'frequency' => 1,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to record category correction', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getLearnedCategory(
        int $tenantId,
        string $text,
        ?string $merchant = null
    ): ?string {
        $normalizedText = mb_strtolower(trim($text));
        $normalizedText = preg_replace('/\s+/', ' ', $normalizedText);

        $correction = CategoryCorrection::where('tenant_id', $tenantId)
            ->where('original_text', $normalizedText)
            ->orderByDesc('frequency')
            ->first();

        if ($correction) {
            return $correction->corrected_category;
        }

        if (! empty($merchant)) {
            $merchantLower = mb_strtolower(trim($merchant));
            $correction = CategoryCorrection::where('tenant_id', $tenantId)
                ->where('merchant', $merchantLower)
                ->orderByDesc('frequency')
                ->first();

            if ($correction) {
                return $correction->corrected_category;
            }
        }

        return null;
    }
}
