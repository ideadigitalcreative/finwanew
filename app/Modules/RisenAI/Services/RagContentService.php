<?php

namespace App\Modules\RisenAI\Services;

class RagContentService
{
    public function __construct(private OpenRouterService $ai) {}

    /**
     * Menyediakan konteks tambahan untuk generator konten (Retrieval-Augmented Generation).
     * Mengambil fakta relevan dari database agar AI tidak berhalusinasi.
     */
    public function getContext(string $topic, array $params = []): string
    {
        try {
            // Pengaman: Jika tabel belum dibuat di VPS, jangan biarkan job FAIL
            if (! \Illuminate\Support\Facades\Schema::hasTable('seo_knowledge_base')) {
                return "PENTING: Gunakan pengetahuan umum tentang Finwa sebagai aplikasi pencatatan keuangan otomatis via WhatsApp (Pesan masuk seperti 'Makan 20rb' otomatis tercatat).";
            }

            $query = \App\Modules\RisenAI\Models\SeoKnowledge::where('is_active', true);

            // 1. Selalu ambil informasi dasar perusahaan/aplikasi
            $coreKnowledge = (clone $query)->whereIn('category', ['company', 'about'])->get();

            // 2. Ambil informasi spesifik topik/layanan
            $topicKeywords = explode(' ', strtolower($topic));
            $relatedKnowledge = (clone $query)->where(function ($q) use ($topicKeywords) {
                foreach ($topicKeywords as $keyword) {
                    if (strlen($keyword) > 3) {
                        $q->orWhere('keywords', 'LIKE', "%{$keyword}%")
                            ->orWhere('topic', 'LIKE', "%{$keyword}%");
                    }
                }
            })->get();

            $knowledgeItems = $coreKnowledge->merge($relatedKnowledge)->unique('id');

            if ($knowledgeItems->isEmpty()) {
                return "PENTING: Gunakan pengetahuan umum tentang Finwa sebagai aplikasi pencatatan keuangan otomatis via WhatsApp (Pesan masuk seperti 'Makan 20rb' otomatis tercatat).";
            }

            $context = "KNOWLEDGE BASE (FAKTA VALID):\n";
            foreach ($knowledgeItems as $item) {
                $context .= '- ['.strtoupper($item->category)."] {$item->topic}: {$item->content}\n";
            }

            return $context;
        } catch (\Throwable $e) {
            \Log::warning('RisenAI RAG Warning: '.$e->getMessage());

            return 'PENTING: Gunakan pengetahuan umum tentang Finwa sebagai aplikasi pencatatan keuangan otomatis via WhatsApp.';
        }
    }
}
