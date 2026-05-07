<?php

namespace App\Services\DebtReceivable;

/**
 * Menyelaraskan respons FinWa-AI untuk empat intent hutang/piutang dengan category_type core-api.
 */
final class FinWaDebtReceivableResponseNormalizer
{
    /** @var list<string> */
    public const DEBT_INTENTS = ['catat_hutang', 'catat_piutang', 'bayar_hutang', 'terima_piutang'];

    public static function intentToCategoryType(string $intent): ?string
    {
        $map = config('finwa_ai_hutang_piutang.intent_to_category_type', []);

        return isset($map[$intent]) && is_string($map[$intent]) ? $map[$intent] : null;
    }

    /**
     * @param  array<string, mixed>  $data  Body JSON FinWa-AI (/process/text)
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $intent = isset($data['intent']) && is_string($data['intent']) ? $data['intent'] : '';
        if (! in_array($intent, self::DEBT_INTENTS, true)) {
            return $data;
        }

        $expected = self::intentToCategoryType($intent);
        if ($expected === null) {
            return $data;
        }

        $entities = isset($data['entities']) && is_array($data['entities']) ? $data['entities'] : [];

        // Intent hutang/piutang adalah sumber kebenaran category_type (selaras TransactionService)
        $entities['category_type'] = $expected;

        $primarySlugs = config('finwa_ai_hutang_piutang.primary_slug_by_category_type', []);
        if (isset($primarySlugs[$expected]) && is_string($primarySlugs[$expected])) {
            $entities['category_slug'] = $primarySlugs[$expected];
        }

        $data['entities'] = $entities;

        return $data;
    }
}
