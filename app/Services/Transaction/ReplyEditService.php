<?php

namespace App\Services\Transaction;

use App\Helpers\KeywordNormalizer;
use App\Models\Balance;
use App\Models\Message;
use App\Models\Transaction;
use App\Services\AI\OpenAIChatClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ReplyEditService - Edit transaksi lewat balasan (reply) pesan WhatsApp.
 *
 * Fase 1 (MVP): resolusi target via pemetaan wa_message_id → transaction_id,
 * alur konfirmasi Ya/Batal, update atomik, dan audit trail.
 *
 * Dokumen: docs/RENCANA-IMPLEMENTASI-REPLY-EDIT.md
 */
class ReplyEditService
{
    protected Message $message;

    protected $sendReplyCallback;

    /** Callback ekstraksi transaksi lokal: fn(string): ?array (Ops — create via reply). */
    protected $extractTransactionCallback;

    /** Callback pembuatan transaksi: fn(array, bool): ?Transaction (Ops — create via reply). */
    protected $createTransactionCallback;

    /** Callback koreksi kategori: fn(?string txKeyword, string catCandidate, Transaction target): void. */
    protected $categoryCorrectionCallback;

    /** Klien LLM hybrid — kenali perintah balasan berbahasa bebas (Hybrid.md §6A). */
    protected OpenAIChatClient $llmClient;

    /**
     * Pesan yang dibalas terpetakan ke transaksi yang sudah dihapus (Transaction, trashed)
     * atau hilang permanen (false). null = tidak ada kasus itu.
     */
    protected Transaction|false|null $mappedTransactionGone = null;

    public function __construct(
        Message $message,
        callable $sendReplyCallback,
        ?callable $extractTransactionCallback = null,
        ?callable $createTransactionCallback = null,
        ?callable $categoryCorrectionCallback = null,
        ?OpenAIChatClient $llmClient = null
    ) {
        $this->message = $message;
        $this->sendReplyCallback = $sendReplyCallback;
        $this->extractTransactionCallback = $extractTransactionCallback;
        $this->createTransactionCallback = $createTransactionCallback;
        $this->categoryCorrectionCallback = $categoryCorrectionCallback;
        $this->llmClient = $llmClient ?? new OpenAIChatClient;
    }

    protected function sendReply(string $text): void
    {
        call_user_func($this->sendReplyCallback, $text);
    }

    /**
     * Ambil reply_context dari raw_data pesan masuk (diisi gateway → webhook).
     *
     * @return array{messageId: string, participant: ?string, text: ?string}|null
     */
    public function getReplyContext(): ?array
    {
        $raw = is_array($this->message->raw_data)
            ? $this->message->raw_data
            : json_decode($this->message->raw_data ?? '{}', true);

        $ctx = $raw['reply_context'] ?? null;

        if (! is_array($ctx) || empty($ctx['messageId'])) {
            return null;
        }

        return [
            'messageId' => (string) $ctx['messageId'],
            'participant' => $ctx['participant'] ?? null,
            'text' => $ctx['text'] ?? null,
        ];
    }

    /**
     * Fase 1d — resolusi prioritas 1: cocokkan stanzaId ke tabel pemetaan.
     *
     * Wajib filter tenant_id (isolasi tenant). Bila grup, pastikan participant
     * pesan yang dibalas sama dengan pengirim pesan reply ini.
     */
    public function resolveTargetTransaction(array $replyContext): ?Transaction
    {
        $row = DB::table('transaction_message_mappings')
            ->where('tenant_id', $this->message->tenant_id)
            ->where('wa_message_id', $replyContext['messageId'])
            ->first();

        if (! $row) {
            return null;
        }

        $transaction = Transaction::withTrashed()
            ->where('tenant_id', $this->message->tenant_id)
            ->where('id', $row->transaction_id)
            ->first();

        // Pesan yang dibalas MILIK transaksi yang sudah dihapus → jangan cari transaksi
        // lain bernominal sama (dulu: fuzzy menemukan transaksi LAIN lalu menawarkan
        // untuk menghapusnya).
        if (! $transaction || $transaction->trashed()) {
            $this->mappedTransactionGone = $transaction ?? false;

            return null;
        }

        if (! $this->groupSenderAllowed($replyContext, $row->wa_sender ?? null)) {
            Log::warning('Reply-edit ditolak: pengirim grup tidak cocok', [
                'message_id' => $this->message->id,
                'transaction_id' => $transaction->id,
            ]);

            return null;
        }

        return $transaction;
    }

    /**
     * Validasi kepemilikan di grup: pengirim reply harus pemilik transaksi.
     *
     * Izinkan bila: (a) participant == pengirim reply, atau
     * (b) wa_sender pemetaan == pengirim reply (membalas balasan Finwa).
     * Tolak bila ada bukti ketidakcocokan. Tanpa bukti sama sekali → izinkan.
     */
    protected function groupSenderAllowed(array $replyContext, ?string $knownSender): bool
    {
        if (! $this->isGroupMessage()) {
            return true;
        }

        $senderDigits = preg_replace('/\D/', '', (string) $this->message->sender_id);
        $participant = preg_replace('/\D/', '', (string) ($replyContext['participant'] ?? ''));

        if ($participant !== '' && $senderDigits !== ''
            && (str_contains($senderDigits, $participant) || str_contains($participant, $senderDigits))) {
            return true;
        }

        if ($knownSender !== null && $knownSender !== '') {
            $known = preg_replace('/\D/', '', $knownSender);

            return $known !== '' && $known === $senderDigits;
        }

        // Tidak ada participant & tidak ada wa_sender → tidak ada bukti
        return $participant === '';
    }

    protected function isGroupMessage(): bool
    {
        $meta = is_array($this->message->metadata)
            ? $this->message->metadata
            : json_decode($this->message->metadata ?? '{}', true);

        return (bool) ($meta['is_group'] ?? false);
    }

    /**
     * Fase 2 — resolusi prioritas 2: fuzzy match isi pesan yang dibalas
     * ke transaksi dalam `fuzzy_window_days` hari terakhir.
     *
     * Skor: kecocokan nominal (wajib) + tumpang tindih token deskripsi.
     *
     * @return array<int, Transaction> kandidat terurut skor desc (maks 5)
     */
    public function resolveFuzzyCandidates(array $replyContext): array
    {
        $quoted = (string) ($replyContext['text'] ?? '');

        if ($quoted === '') {
            return [];
        }

        $quotedAmount = $this->extractAmount($quoted);

        if ($quotedAmount === null) {
            return []; // Tidak ada nominal di pesan dibalas → tidak bisa dipercaya
        }

        $window = (int) config('finwa_reply_edit.fuzzy_window_days', 30);

        $transactions = Transaction::where('tenant_id', $this->message->tenant_id)
            ->where('transaction_date', '>=', now()->subDays($window))
            ->orderByDesc('transaction_date')
            ->limit(100)
            ->get();

        // Deskripsi & jenis dari pesan yang dibalas. Pesan konfirmasi bot berformat
        // "Kategori • deskripsi"; kata template ("Siap, Sudah Masuk!", "Sisa saldo")
        // TIDAK boleh ikut dicocokkan — dulu "Masuk" di judul membuat transaksi lain
        // ("ada uang masuk") dianggap sama dengan "Masuk dana".
        $quotedDesc = $this->quotedDescription($quoted);
        $quotedTokens = $this->tokenize($quotedDesc);
        $quotedType = $this->quotedType($quoted);
        $scored = [];

        foreach ($transactions as $tx) {
            if ((int) $tx->amount !== $quotedAmount) {
                continue; // nominal wajib cocok
            }
            if ($quotedType !== null && $tx->type !== $quotedType) {
                continue; // jenis wajib cocok bila pesan menyebutnya
            }

            $descTokens = $this->tokenize(KeywordNormalizer::stripAmountFromDescription((string) ($tx->description ?? '')));
            $common = array_intersect($descTokens, $quotedTokens);
            $union = array_unique(array_merge($descTokens, $quotedTokens));

            // Deskripsi wajib mirip: sama persis, atau ≥ separuh kata sama
            $similarity = $union === [] ? 0.0 : count($common) / count($union);
            if ($quotedTokens === [] || $similarity < 0.5) {
                continue;
            }

            $scored[] = ['tx' => $tx, 'score' => 50 + (int) round($similarity * 50)];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice(array_column($scored, 'tx'), 0, 5);
    }

    /**
     * Deskripsi transaksi dari pesan yang dibalas:
     *   konfirmasi bot "💵 Pendapatan Lainnya • _Masuk dana_" → "Masuk dana"
     *   pesan user sendiri "beli kopi 25rb"                    → "beli kopi"
     */
    protected function quotedDescription(string $quoted): string
    {
        foreach (preg_split('/\R/u', $quoted) ?: [] as $line) {
            if (str_contains($line, '•')) {
                $after = trim((string) mb_substr($line, mb_strrpos($line, '•') + 1));

                return trim((string) preg_replace('/[*_~`]+/u', '', $after));
            }
        }

        // Bukan pesan konfirmasi bot (mis. user membalas pesannya sendiri)
        $looksLikeBot = (bool) preg_match('/(?:sudah\s+masuk|dicatat|tercatat|sisa\s+saldo|berhasil)/iu', $quoted);

        return $looksLikeBot ? '' : KeywordNormalizer::stripAmountFromDescription($quoted);
    }

    /** 'income' / 'expense' bila pesan yang dibalas menyebut jenisnya, selain itu null. */
    protected function quotedType(string $quoted): ?string
    {
        $hasIncome = (bool) preg_match('/\bpemasukan\b/iu', $quoted);
        $hasExpense = (bool) preg_match('/\bpengeluaran\b/iu', $quoted);

        return $hasIncome === $hasExpense ? null : ($hasIncome ? 'income' : 'expense');
    }

    /**
     * Tokenisasi sederhana untuk perbandingan deskripsi (buang kata umum).
     *
     * @return array<int, string>
     */
    protected function tokenize(string $text): array
    {
        $stopwords = [
            'yang', 'dan', 'di', 'ke', 'dari', 'ini', 'itu', 'untuk', 'dengan',
            'rp', 'rb', 'ribu', 'juta', 'jt', 'udah', 'sudah', 'kok', 'deh',
            'makan', 'beli', 'bayar', 'buat', 'yg', 'nya', 'kali', 'the',
        ];

        $tokens = preg_split('/[^\pL\pN]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_filter($tokens, fn ($t) => mb_strlen($t) >= 3 && ! in_array($t, $stopwords, true));

        return array_values(array_unique($tokens));
    }

    /**
     * Apakah pesan ini balasan konfirmasi ("Ya"/"Batal") untuk edit tertunda?
     */
    public function pendingConfirmation(): ?array
    {
        $state = Cache::get($this->confirmationStateKey());

        return is_array($state) ? $state : null;
    }

    /**
     * Entry point dari routing ProcessIncomingMessage.
     * - Bila ada state pilihan/ konfirmasi pending → handle respons.
     * - Bila reply context ada → mapping dulu, lalu fuzzy fallback.
     *
     * @return bool true bila pesan dikonsumsi alur reply-edit
     */
    public function handle(): bool
    {
        // Fase 4 — guard canary tenant (bila tenant tidak di daftar → serah flow lama)
        if (! $this->tenantAllowed()) {
            return false;
        }

        // Bot sedang menunggu jawaban pertanyaan bernomor ("Balas 1/2/3") → biarkan alur
        // utama yang menjawab, walau user membalas dengan fitur quote. Dulu teks kutipan
        // ("Catat Rp 2.000.000…") bisa dicocokkan ke transaksi lama → alur edit yang salah.
        $meta = is_array($this->message->metadata) ? $this->message->metadata : (json_decode((string) ($this->message->metadata ?? '{}'), true) ?: []);
        $attributionSender = ($meta['is_group'] ?? false) && ! empty($meta['author'])
            ? (string) $meta['author'] // anggota grup, sama dengan kunci pertanyaan di alur utama
            : (string) $this->message->sender_id;
        if (\App\Services\WhatsApp\Clarification::pending(
            (int) $this->message->tenant_id,
            \App\Support\SenderIdentity::keys($this->message, $attributionSender)
        ) !== null) {
            return false;
        }

        // Mode dry-run = BAYANGAN: tidak boleh terlihat user. Dulu bot meminta konfirmasi
        // hapus/ubah lalu membalas "(dry-run) … belum diterapkan" — user mengira sudah
        // terhapus padahal tidak. Sekarang hanya dicatat di log; pesan diproses alur biasa.
        // Aktifkan sungguhan dengan FINWA_REPLY_EDIT_DRY_RUN=false.
        if ($this->isDryRun()) {
            if (($ctx = $this->getReplyContext()) !== null) {
                Log::info('[reply-edit dry-run] dilewati (bayangan)', [
                    'message_id' => $this->message->id,
                    'reply_to' => $ctx['messageId'],
                    'mapped_transaction_id' => optional($this->resolveTargetTransaction($ctx))->id,
                ]);
            }

            return false;
        }

        $state = $this->pendingConfirmation();

        if ($state !== null) {
            if (! $this->rateLimitOk()) {
                return true; // habiskan, jangan proses
            }

            $mode = $state['mode'] ?? 'confirm';

            // Ops — state baru (daftar riwayat bernomor & create via reply)
            if ($mode === 'list_pick') {
                $this->handleListPickResponse($state);

                return true;
            }

            if ($mode === 'await_change') {
                // Bila pesan tidak membawa perubahan, state dilepas dan
                // pesan diteruskan ke flow lama (return false).
                return $this->handleAwaitChangeResponse($state);
            }

            if ($mode === 'confirm_create') {
                $this->handleCreateConfirmationResponse($state);

                return true;
            }

            if ($mode === 'pick') {
                $this->handlePickResponse($state);

                return true;
            }

            if ($mode === 'confirm_delete') {
                $this->handleDeleteResponse($state);

                return true;
            }

            $this->handleConfirmationResponse($state);

            return true;
        }

        $replyContext = $this->getReplyContext();

        if ($replyContext === null) {
            return false; // Bukan reply → biarkan flow lama yang memproses
        }

        if (! $this->rateLimitOk()) {
            return true;
        }

        $transaction = $this->resolveTargetTransaction($replyContext);

        if ($transaction) {
            $this->startEditFlow($transaction, $replyContext, 'mapping');
            $this->countResolution('mapping');

            return true;
        }

        if ($this->mappedTransactionGone !== null) {
            $this->sendReply($this->mappedTransactionGone instanceof Transaction
                ? "ℹ️ *Transaksi ini sudah dihapus sebelumnya.*\n\n↩️ Ketik *kembalikan* untuk memulihkannya (berlaku 30 hari)."
                : 'ℹ️ Transaksi ini sudah tidak ada di catatan.');
            $this->countResolution('mapping');

            return true;
        }

        // Ops — balasan ke pesan daftar riwayat bernomor → tampilkan pilihan
        $listIds = self::getListPayload((int) $this->message->tenant_id, (string) $replyContext['messageId']);
        if ($listIds !== null) {
            // Balasan sudah berisi perintah bernomor ("1 hapus", "2 ubah jadi 50rb") →
            // alur utama yang menjalankannya. Dulu daftar pilihan ditampilkan ULANG dan
            // perintah user diabaikan.
            $content = (string) $this->message->content;
            if (TransactionService::parseListDeleteIndex($content) !== null || TransactionService::parseListIndex($content) !== null) {
                return false;
            }


            $this->startListPick($listIds, $replyContext);
            $this->countResolution('manual_pick');

            return true;
        }

        // Fase 2 — fallback fuzzy (selalu dikonfirmasi, tidak pernah langsung update)
        $candidates = $this->resolveFuzzyCandidates($replyContext);

        if (count($candidates) === 1) {
            $this->startEditFlow($candidates[0], $replyContext, 'fuzzy');
            $this->countResolution('fuzzy');

            return true;
        }

        if (count($candidates) > 1) {
            $this->startPickFlow($candidates, $replyContext);
            $this->countResolution('manual_pick');

            return true;
        }

        // Ops — bukan target edit: coba tangkap sebagai transaksi BARU via reply
        if ($this->startCreateFromReply($replyContext)) {
            return true;
        }

        // "hapus" sebagai balasan ke pesan TERTENTU yang transaksinya tak bisa dipastikan →
        // jangan jatuh ke alur lama (di sana "hapus" = transaksi TERAKHIR, bisa transaksi lain).
        if ($this->isDeleteCommand($this->message->content)) {
            $this->countResolution('unresolved');
            $this->sendReply(
                "⚠️ *Transaksi dari pesan yang Anda balas tidak ditemukan.*\n\n".
                "Ketik *lihat transaksi*, lalu balas daftar itu dengan nomor transaksi yang mau dihapus.\n".
                '_Atau ketik *batal* untuk menghapus transaksi terakhir._'
            );

            return true;
        }

        $this->countResolution('unresolved');
        Log::info('Reply-edit: mapping & fuzzy tidak ditemukan, fallback ke flow lama', [
            'message_id' => $this->message->id,
            'reply_to' => $replyContext['messageId'],
        ]);

        return false;
    }

    /**
     * Fase 4 — canary: hanya tenant yang diizinkan (kosong = semua).
     */
    protected function tenantAllowed(): bool
    {
        $allowed = config('finwa_reply_edit.allowed_tenants', []);

        if (! is_array($allowed) || $allowed === []) {
            return true;
        }

        return in_array((int) $this->message->tenant_id, $allowed, true);
    }

    /**
     * Fase 4 — rate limit per pengirim. Bila lewat, kirim notifikasi sekali
     * dan abaikan pesan (tetap dikonsumsi agar flow lama tidak mengulang).
     */
    protected function rateLimitOk(): bool
    {
        $max = (int) config('finwa_reply_edit.rate_limit_max', 0);

        if ($max <= 0) {
            return true; // nonaktif
        }

        $decay = (int) config('finwa_reply_edit.rate_limit_decay', 60);
        $key = 'finwa_reply_edit_rl:'.$this->message->tenant_id.':'.$this->message->sender_id;

        $hits = (int) Cache::get($key, 0);

        if ($hits >= $max) {
            $notifiedKey = $key.':notified';
            if (! Cache::has($notifiedKey)) {
                Cache::put($notifiedKey, 1, $decay);
                $this->sendReply(
                    "⏳ Terlalu banyak permintaan edit. Tunggu {$decay} detik lalu coba lagi."
                );
            }

            return false;
        }

        if ($hits === 0) {
            Cache::put($key, 1, $decay);
        } else {
            Cache::increment($key);
        }

        return true;
    }

    /**
     * Fase 4 — hitung metrik resolusi per prioritas (log + counter ringan).
     */
    protected function countResolution(string $method): void
    {
        Cache::increment('finwa_reply_edit_metrics:'.$method);

        Log::info('[reply-edit metric] resolution', [
            'method' => $method,
            'tenant_id' => $this->message->tenant_id,
        ]);

        // Peringatan bila rasio ambigu (manual_pick) terlalu tinggi
        if ($method !== 'unresolved') {
            $total = 0;
            $ambiguous = 0;
            foreach (['mapping', 'fuzzy', 'manual_pick'] as $m) {
                $c = (int) Cache::get('finwa_reply_edit_metrics:'.$m, 0);
                $total += $c;
                if ($m === 'manual_pick') {
                    $ambiguous = $c;
                }
            }

            $threshold = (int) config('finwa_reply_edit.fuzzy_ambiguity_alert_pct', 60);
            if ($total >= 10 && $threshold > 0 && ($ambiguous * 100 / $total) > $threshold) {
                Log::warning('[reply-edit metric] ambiguitas fuzzy tinggi — pertimbangkan penyesuaian skoring', [
                    'pct' => round($ambiguous * 100 / $total, 1),
                    'threshold' => $threshold,
                ]);
            }
        }
    }

    /**
     * Fase 2 — tampilkan daftar bernomor bila fuzzy ambigu (prioritas 3).
     */
    protected function startPickFlow(array $candidates, array $replyContext): void
    {
        $lines = ["⚠️ *Transaksi mana yang dimaksud?*\n"];
        $ids = [];

        foreach (array_values($candidates) as $i => $tx) {
            $n = $i + 1;
            $ids[] = $tx->id;
            $lines[] = (
                "{$n}. ".(KeywordNormalizer::stripAmountFromDescription($tx->description) ?: 'Transaksi')."\n".
                '   💸 Rp '.number_format((int) $tx->amount, 0, ',', '.').' · '.
                $tx->transaction_date->translatedFormat('d M Y')
            );
        }

        $lines[] = "\nBalas *angka* pilihan (1-".count($ids).") atau *Batal*.";

        Cache::put(
            $this->confirmationStateKey(),
            [
                'mode' => 'pick',
                'candidate_ids' => $ids,
                'reply_context' => $replyContext,
                'expires_at' => now()->addSeconds(config('finwa_reply_edit.confirmation_ttl', 300))->timestamp,
            ],
            now()->addSeconds(config('finwa_reply_edit.confirmation_ttl', 300))
        );

        $this->sendReply(implode("\n", $lines));
    }

    /**
     * Tahap 1b: user memilih nomor kandidat → lanjut ke preview konfirmasi.
     */
    protected function handlePickResponse(array $state): void
    {
        $text = trim($this->message->content ?? '');

        if (now()->timestamp > (int) ($state['expires_at'] ?? 0)) {
            Cache::forget($this->confirmationStateKey());
            $this->sendReply('⏳ Pilihan kedaluwarsa. Balas pesan transaksi lagi untuk mengulang.');

            return;
        }

        if (preg_match('/^(batal|tidak|no|cancel|stop)[.! ]*$/iu', $text)) {
            Cache::forget($this->confirmationStateKey());
            $this->sendReply('👌 Oke, dibatalkan.');

            return;
        }

        $ids = $state['candidate_ids'] ?? [];

        if (! preg_match('/^\d{1,2}$/', $text)
            || ($n = (int) $text) < 1 || $n > count($ids)) {
            $this->sendReply('Balas *angka* pilihan (1-'.count($ids).') atau *Batal*.');

            return;
        }

        $transaction = Transaction::where('tenant_id', $this->message->tenant_id)
            ->where('id', $ids[$n - 1])
            ->first();

        if (! $transaction) {
            Cache::forget($this->confirmationStateKey());
            $this->sendReply('⚠️ Transaksi tidak ditemukan. Coba lagi.');

            return;
        }

        $this->startEditFlow($transaction, $state['reply_context'] ?? [], 'manual_pick');
    }

    /*
    |--------------------------------------------------------------------------
    | Ops — Daftar riwayat bernomor (balas angka → pilih transaksi)
    |--------------------------------------------------------------------------
    */

    /**
     * Simpan payload daftar riwayat (urutan transaction_id) terhadap
     * messageId pesan keluar, agar balasan ke pesan daftar bisa dipetakan.
     */
    public static function storeListPayload(int $tenantId, string $waMessageId, array $transactionIds): void
    {
        try {
            Cache::put(
                'finwa_reply_list:'.$tenantId.':'.$waMessageId,
                array_values($transactionIds),
                now()->addSeconds((int) config('finwa_reply_edit.list_ttl', 3600))
            );
        } catch (\Exception $e) {
            Log::warning('Reply-edit: gagat simpan payload daftar', ['error' => $e->getMessage()]);
        }
    }

    public static function getListPayload(int $tenantId, string $waMessageId): ?array
    {
        $ids = Cache::get('finwa_reply_list:'.$tenantId.':'.$waMessageId);

        return is_array($ids) && $ids !== [] ? $ids : null;
    }

    /**
     * Mulai alur pilihan dari daftar riwayat bernomor.
     */
    protected function startListPick(array $ids, array $replyContext): void
    {
        $transactions = Transaction::where('tenant_id', $this->message->tenant_id)
            ->whereIn('id', $ids)
            ->orderByRaw('FIELD(id, '.implode(',', array_map('intval', $ids)).')')
            ->get();

        if ($transactions->isEmpty()) {
            Cache::forget('finwa_reply_list:'.$this->message->tenant_id.':'.$replyContext['messageId']);
            $this->sendReply('⚠️ Daftar transaksi sudah kedaluwarsa. Minta daftar terbaru.');

            return;
        }

        $lines = ["📝 *Pilih transaksi untuk diubah*\n"];

        foreach ($transactions as $i => $tx) {
            $n = $i + 1;
            $lines[] = (
                "{$n}. ".($tx->description ?: 'Transaksi')."\n".
                '   💸 Rp '.number_format((int) $tx->amount, 0, ',', '.').' · '.
                $tx->transaction_date->translatedFormat('d M Y')
            );
        }

        $lines[] = "\nBalas *nomor* (1-".count($transactions).") lalu tulis perubahannya, atau *Batal*.";

        Cache::put(
            $this->confirmationStateKey(),
            [
                'mode' => 'list_pick',
                'candidate_ids' => $transactions->pluck('id')->all(),
                'reply_context' => $replyContext,
                'expires_at' => now()->addSeconds(config('finwa_reply_edit.confirmation_ttl', 300))->timestamp,
            ],
            now()->addSeconds(config('finwa_reply_edit.confirmation_ttl', 300))
        );

        $this->sendReply(implode("\n", $lines));
    }

    /**
     * User membalas daftar riwayat dengan nomor → ingat target, minta perubahan.
     */
    protected function handleListPickResponse(array $state): void
    {
        $text = trim($this->message->content ?? '');

        if (now()->timestamp > (int) ($state['expires_at'] ?? 0)) {
            Cache::forget($this->confirmationStateKey());
            $this->sendReply('⏳ Pilihan kedaluwarsa. Minta daftar transaksi terbaru.');

            return;
        }

        if (preg_match('/^(batal|tidak|no|cancel|stop)[.! ]*$/iu', $text)) {
            Cache::forget($this->confirmationStateKey());
            $this->sendReply('👌 Oke, dibatalkan.');

            return;
        }

        $ids = $state['candidate_ids'] ?? [];

        if (! preg_match('/^\d{1,2}$/', $text)
            || ($n = (int) $text) < 1 || $n > count($ids)) {
            $this->sendReply('Balas *nomor* (1-'.count($ids).') atau *Batal*.');

            return;
        }

        $transaction = Transaction::where('tenant_id', $this->message->tenant_id)
            ->where('id', $ids[$n - 1])
            ->first();

        if (! $transaction) {
            Cache::forget($this->confirmationStateKey());
            $this->sendReply('⚠️ Transaksi tidak ditemukan. Minta daftar terbaru.');

            return;
        }

        // Ganti state: tunggu perubahan berikutnya sebagai pesan biasa
        Cache::put(
            $this->confirmationStateKey(),
            [
                'mode' => 'await_change',
                'transaction_id' => $transaction->id,
                'resolve_method' => 'manual_pick',
                'expires_at' => now()->addSeconds(config('finwa_reply_edit.confirmation_ttl', 300))->timestamp,
            ],
            now()->addSeconds(config('finwa_reply_edit.confirmation_ttl', 300))
        );

        $this->sendReply(
            "✏️ *Transaksi dipilih:*\n\n".
            (KeywordNormalizer::stripAmountFromDescription($transaction->description) ?: 'Transaksi')."\n".
            '💸 Rp '.number_format((int) $transaction->amount, 0, ',', '.')."\n".
            '📅 '.$transaction->transaction_date->translatedFormat('d M Y')."\n\n".
            "Sekarang balas dengan perubahan, contoh:\n".
            "• Nominal: *50rb*\n".
            "• Tanggal: *tgl 21/09/2026*\n".
            "• Hapus: *hapus*\n".
            '• Batal: *Batal*'
        );
    }

    /**
     * Tangkap pesan berikutnya sebagai perubahan untuk transaksi terpilih.
     *
     * @return bool true bila pesan dikonsumsi; false bila state dilepas dan
     *              pesan diteruskan ke flow lama (mencegah pesan acak tertelan).
     */
    protected function handleAwaitChangeResponse(array $state): bool
    {
        $text = trim($this->message->content ?? '');

        if (now()->timestamp > (int) ($state['expires_at'] ?? 0)) {
            Cache::forget($this->confirmationStateKey());
            $this->sendReply('⏳ Waktu edit habis. Balas pesan daftar lagi untuk memulai.');

            return true;
        }

        if (preg_match('/^(batal|tidak|no|cancel|stop)[.! ]*$/iu', $text)) {
            Cache::forget($this->confirmationStateKey());
            $this->sendReply('👌 Oke, perubahan dibatalkan.');

            return true;
        }

        $transaction = Transaction::where('tenant_id', $this->message->tenant_id)
            ->where('id', $state['transaction_id'] ?? 0)
            ->first();

        if (! $transaction) {
            Cache::forget($this->confirmationStateKey());
            $this->sendReply('⚠️ Transaksi tidak ditemukan. Minta daftar terbaru.');

            return true;
        }

        // Hanya konsumsi bila pesan benar-benar membawa perubahan
        // (regex + LLM — frasa bebas seperti "taruh di bumbu dapur" ikut terbaca)
        $command = $this->parseEditCommand($text);
        $hasChange = $command !== null && $command['action'] !== 'none';

        if (! $hasChange) {
            Cache::forget($this->confirmationStateKey());

            return false; // lepas state → biarkan flow lama memproses
        }

        $this->startEditFlow($transaction, [], $state['resolve_method'] ?? 'manual_pick');

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Ops — Catat transaksi baru via balasan pesan
    |--------------------------------------------------------------------------
    */

    /**
     * Coba tangkap balasan pesan sebagai transaksi BARU (create via reply).
     *
     * Dipakai bila mapping & fuzzy tidak menemukan target edit: pesan balasan
     * yang mengandung nominal dianggap niat pencatatan transaksi baru.
     *
     * @return bool true bila preview terkirim (pesan dikonsumsi)
     */
    protected function startCreateFromReply(array $replyContext): bool
    {
        if ($this->extractTransactionCallback === null || $this->createTransactionCallback === null) {
            return false;
        }

        $text = trim((string) ($this->message->content ?? ''));

        if ($text === '' || $this->extractAmount($text) === null) {
            return false; // tanpa nominal → bukan niat catat transaksi
        }

        $row = call_user_func($this->extractTransactionCallback, $text);

        if (! is_array($row) || empty($row['amount'])) {
            return false;
        }

        if (! in_array($row['type'] ?? '', ['income', 'expense'], true)) {
            return false; // ambiguous → serahkan ke flow konfirmasi ambigu lama
        }

        Log::info('Reply-edit: create via reply dipicu', [
            'message_id' => $this->message->id,
            'reply_to' => $replyContext['messageId'] ?? null,
            'amount' => $row['amount'],
            'type' => $row['type'],
        ]);

        $this->startCreatePreview($row);

        return true;
    }

    /**
     * Kirim preview transaksi hasil ekstraksi dari balasan pesan.
     *
     * @param array $row hasil extractTransactionLocally()
     */
    protected function startCreatePreview(array $row): void
    {
        if (! in_array($row['type'] ?? '', ['income', 'expense'], true)) {
            return; // ambiguous/tak dikenal → serahkan ke flow lama
        }

        $typeEmoji = $row['type'] === 'income' ? '💰' : '💸';
        $typeLabel = $row['type'] === 'income' ? 'Pemasukan' : 'Pengeluaran';
        $amount = (int) $row['amount'];
        $date = \Illuminate\Support\Carbon::parse($row['transaction_date'] ?? now());

        Cache::put(
            $this->confirmationStateKey(),
            [
                'mode' => 'confirm_create',
                'row' => $row,
                'expires_at' => now()->addSeconds(config('finwa_reply_edit.confirmation_ttl', 300))->timestamp,
            ],
            now()->addSeconds(config('finwa_reply_edit.confirmation_ttl', 300))
        );

        $this->sendReply(
            "📝 *Catat transaksi baru?*\n\n".
            "{$typeEmoji} *{$typeLabel}*\n".
            '💵 Rp '.number_format($amount, 0, ',', '.')."\n".
            '📝 '.(KeywordNormalizer::stripAmountFromDescription($row['description'] ?? '') ?: '-')."\n".
            '📅 '.$date->translatedFormat('d M Y')."\n\n".
            "Balas *Ya* untuk simpan, *Batal* untuk batalkan."
        );
    }

    /**
     * Konfirmasi Ya/Batal untuk pembuatan transaksi via reply.
     */
    protected function handleCreateConfirmationResponse(array $state): void
    {
        $text = trim(mb_strtolower($this->message->content ?? ''));

        if (now()->timestamp > (int) ($state['expires_at'] ?? 0)) {
            Cache::forget($this->confirmationStateKey());
            $this->sendReply('⏳ Konfirmasi kedaluwarsa. Balas ulang pesan Anda untuk mengulang.');

            return;
        }

        if (preg_match('/^(batal|tidak|tidak jadi|no|cancel|stop)[.! ]*$/u', $text)) {
            Cache::forget($this->confirmationStateKey());
            $this->sendReply('👌 Oke, transaksi tidak jadi dicatat.');

            return;
        }

        if (! preg_match('/^(ya|y|yes|oke|ok|siap|catat|lanjut)[.! ]*$/u', $text)) {
            $this->sendReply('Konfirmasi catat transaksi:\nBalas *Ya* untuk simpan, *Batal* untuk batal.');

            return;
        }

        if ($this->createTransactionCallback === null) {
            Cache::forget($this->confirmationStateKey());
            $this->sendReply('⚠️ Fitur ini belum tersedia.');

            return;
        }

        if (config('finwa_reply_edit.dry_run', true)) {
            Cache::forget($this->confirmationStateKey());
            Log::info('[reply-edit dry-run] create via reply dibatalkan (flag aktif)', [
                'tenant_id' => $this->message->tenant_id,
                'amount' => $state['row']['amount'] ?? null,
            ]);
            $this->sendReply('✅ (dry-run) Transaksi tercatat di log — belum diterapkan ke database.');

            return;
        }

        $transaction = call_user_func($this->createTransactionCallback, $state['row'], false);

        Cache::forget($this->confirmationStateKey());

        if (! $transaction) {
            // createTransaction sudah mengirim pesan balasan sendiri (mis. limit paket)
            return;
        }

        $this->sendReply(
            "✅ *Transaksi dicatat!*\n\n".
            ($transaction->type === 'income' ? '💰' : '💸').' Rp '.number_format((int) $transaction->amount, 0, ',', '.')."\n".
            '📝 '.(KeywordNormalizer::stripAmountFromDescription($transaction->description) ?: '-')."\n".
            '📅 '.$transaction->transaction_date->translatedFormat('d M Y')
        );

        // Pasang pemetaan langsung → balasan berikutnya ke pesan ini bisa diedit
        if (config('finwa_reply_edit.enabled', false)) {
            $waOut = Cache::get('wa_out_msgid:'.$this->message->message_id);
            if ($waOut) {
                self::storeMapping(
                    (int) $this->message->tenant_id,
                    null,
                    (string) $waOut,
                    (int) $transaction->id,
                    $this->message->sender_id
                );
            }
        }
    }

    /**
     * Tahap 1: ekstrak perubahan dari balasan, kirim preview + minta konfirmasi.
     */
    protected function startEditFlow(Transaction $transaction, array $replyContext, string $resolveMethod = 'mapping'): void
    {
        $transaction->load('category');

        // Ops — balasan berisi perintah koreksi kategori ("Ubah kategorinya jadi
        // bahan makanan", "taruh di bumbu dapur"): alur reply-edit hanya paham
        // nominal/tanggal/hapus, jadi tanpa penanganan ini pesan jatuh ke prompt
        // template berulang. Dikenali via regex lalu LLM (parseEditCommand).
        // Target = transaksi yang dibalas.
        $command = $this->parseEditCommand($this->message->content ?? '');
        if ($command !== null && $command['action'] === 'category') {
            if ($this->categoryCorrectionCallback !== null) {
                call_user_func(
                    $this->categoryCorrectionCallback,
                    $command['value'],
                    $transaction
                );
            } else {
                $this->sendReply('⚠️ Fitur ubah kategori tidak tersedia.');
            }

            return;
        }

        $oldAmount = (int) $transaction->amount;

        if ($command !== null && $command['action'] === 'amount') {
            $newAmount = (int) $command['value'];
        } else {
            // LLM tidak terlibat saat menafsirkan transaksi baru di bawah —
            // regex cukup (nominal pesan transaksi baru selalu terbaca).
            $newAmount = $this->extractAmount($this->message->content);
        }

        if ($command !== null && $command['action'] === 'date') {
            $newDate = \Illuminate\Support\Carbon::parse($command['value']);
        } else {
            $newDate = $this->extractDate($this->message->content);
        }

        // Fase 3 — balasan berupa perintah hapus
        $isDelete = $command !== null
            ? $command['action'] === 'delete'
            : $this->isDeleteCommand($this->message->content);

        if ($isDelete) {
            $this->startDeleteFlow($transaction, $replyContext, $resolveMethod);

            return;
        }

        // Tidak ada perubahan apa pun → minta input
        if (($newAmount === null || $newAmount === $oldAmount)
            && $newDate === null) {
            $this->sendReply(
                "✏️ *Edit Transaksi*\n\n".
                "Anda membalas transaksi:\n".
                "💸 Rp ".number_format($oldAmount, 0, ',', '.')."\n".
                "📝 ".(KeywordNormalizer::stripAmountFromDescription($transaction->description) ?: '-')."\n".
                "📅 ".$transaction->transaction_date->translatedFormat('d M Y')."\n\n".
                "Balas dengan perubahan, contoh:\n".
                "• Nominal: *25rb*\n".
                "• Tanggal: *tgl 21/09/2026*\n".
                "• Hapus: *hapus*"
            );

            return;
        }

        if ($newAmount === null || $newAmount === $oldAmount) {
            $newAmount = $oldAmount;
        }

        if ($this->isDryRun()) {
            Log::info('[reply-edit dry-run] konfirmasi diminta', [
                'transaction_id' => $transaction->id,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'new_date' => $newDate?->toDateString(),
            ]);
        }

        // Simpan state konfirmasi (TTL dari config, default 300 detik)
        Cache::put(
            $this->confirmationStateKey(),
            [
                'mode' => 'confirm',
                'transaction_id' => $transaction->id,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'old_date' => $transaction->transaction_date->toDateString(),
                'new_date' => $newDate?->toDateString(),
                'resolve_method' => $resolveMethod,
                'wa_message_id' => $replyContext['messageId'] ?? null,
                'expires_at' => now()->addSeconds(config('finwa_reply_edit.confirmation_ttl', 300))->timestamp,
            ],
            now()->addSeconds(config('finwa_reply_edit.confirmation_ttl', 300))
        );

        $changeLines = [];
        if ($newAmount !== $oldAmount) {
            $changeLines[] = '💵 Rp '.number_format($oldAmount, 0, ',', '.')
                .' → Rp '.number_format($newAmount, 0, ',', '.');
        }
        if ($newDate !== null) {
            $changeLines[] = '📅 '.$transaction->transaction_date->translatedFormat('d M Y')
                .' → '.$newDate->translatedFormat('d M Y');
        }

        $this->sendReply(
            "✏️ *Ubah transaksi ini?*\n\n".
            "📋 ".(KeywordNormalizer::stripAmountFromDescription($transaction->description) ?: 'Transaksi')."\n".
            "📁 ".($transaction->category->name ?? 'Lainnya')."\n".
            "📅 ".$transaction->transaction_date->translatedFormat('d M Y')."\n\n".
            implode("\n", $changeLines)."\n\n".
            "Balas *Ya* untuk simpan, *Batal* untuk batalkan."
        );
    }

    /**
     * Kenali perintah edit dalam balasan: kategori / nominal / tanggal / hapus.
     *
     * Hirarki (Hybrid.md §6A): regex deterministik dulu (cepat, tanpa biaya),
     * lalu LLM hybrid untuk frasa bebas yang tak terduga ("pindahin ke belanja
     * dong", "taruh di kategori bumbu"). LLM diakhiri supaya jalur umum tetap
     * instan.
     *
     * @return array{action: 'category'|'amount'|'date'|'delete', value?: mixed}|null
     *         null bila bukan perintah edit
     */
    protected function parseEditCommand(?string $text): ?array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        // — Fast path deterministik —
        $cat = $this->extractCategoryCandidate($text);
        if ($cat !== null) {
            return ['action' => 'category', 'value' => $cat];
        }

        $amount = $this->extractAmount($text);
        if ($amount !== null) {
            return ['action' => 'amount', 'value' => $amount];
        }

        $date = $this->extractDate($text);
        if ($date !== null) {
            return ['action' => 'date', 'value' => $date->toDateString()];
        }

        if ($this->isDeleteCommand($text)) {
            return ['action' => 'delete'];
        }

        // — Fallback LLM untuk frasa bebas —
        // Short-circuit: jawaban konfirmasi/penolakan & sapaan pendek bukan
        // perintah edit — jangan bayar latensi LLM (endpoint 8-28 dtk).
        if (preg_match(
            '/^\s*(?:ya|y|yes|no|tidak|tdk|batal|gak|gk|enggak|oke|ok|siap|lanjut|stop|cancel|thanks|makasih|halo|hai|hi|pagi|siang|sore|malam|apa kabar)[.! ]*$/iu',
            $text
        )) {
            return null;
        }

        return $this->parseEditCommandViaLlm($text);
    }

    /**
     * Tafsirkan perintah edit lewat LLM hybrid. Tidak pernah melempar
     * exception: gagal/timeout/konfigurasi mati → null (balik ke alur lama).
     */
    protected function parseEditCommandViaLlm(string $text): ?array
    {
        $systemPrompt = <<<'PROMPT'
Kamu menafsirkan balasan user di WhatsApp yang mengedit SATU transaksi keuangan (FinWa).

Balas HANYA JSON valid, tanpa markdown, tanpa penjelasan:
{"action":"amount","value":25000}
{"action":"date","value":"2026-09-21"}
{"action":"category","value":"bahan makanan"}
{"action":"delete"}
{"action":"none"}

Aturan:
- amount = RUPIAH BULAT dari nominal yang disebut: "25rb"/"25k"=25000, "1,5jt"/"1.5jt"=1500000, "800000"=800000. Jangan mengarang angka.
- date = YYYY-MM-DD. Hari ini adalah {today}.
- category = nama kategori TUJUAN saja, tanpa kata "jadi/ke/menjadi/ke kategori" ("taruh di bumbu dapur" → "bumbu dapur", "pindahin ke belanja" → "belanja").
- delete = perintah menghapus transaksi ("buang aja", "hapus yang ini").
- none = bukan perintah edit: sapaan, pertanyaan, obrolan, ATAU transaksi BARU yang berisi nominal ("siram 12rb").
- Ragu-ragu → "none".
PROMPT;
        $systemPrompt = str_replace('{today}', now()->toDateString(), $systemPrompt);

        try {
            $raw = $this->llmClient->complete(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $text],
                ],
                maxTokens: 120,
                temperature: 0.0,
                extraParams: ['response_format' => ['type' => 'json_object']],
            );
        } catch (\Throwable $e) {
            Log::warning('ReplyEdit: LLM parse gagal', ['error' => $e->getMessage()]);

            return null;
        }

        if ($raw === null) {
            return null; // konfigurasi mati / error HTTP → alur lama
        }

        $clean = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw));
        $data = json_decode($clean, true);

        if (! is_array($data) || ! isset($data['action'])) {
            Log::warning('ReplyEdit: JSON LLM tidak valid', ['raw_preview' => mb_substr($raw, 0, 200)]);

            return null;
        }

        $result = match ($data['action']) {
            'category' => $this->validateLlmCategory($data['value'] ?? null),
            'amount' => $this->validateLlmAmount($data['value'] ?? null),
            'date' => $this->validateLlmDate($data['value'] ?? null),
            'delete' => ['action' => 'delete'],
            default => null,
        };

        Log::info('ReplyEdit: hasil parse LLM', [
            'message_id' => $this->message->id,
            'text' => mb_substr($text, 0, 120),
            'action' => $result['action'] ?? 'none',
        ]);

        return $result;
    }

    /** Validasi nilai kategori dari LLM (bersih, bukan angka, bukan frasa kosong). */
    protected function validateLlmCategory(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $candidate = TransactionService::cleanCategoryCandidate(trim($value));

        if ($candidate === ''
            || mb_strlen($candidate) < 2
            || preg_match('/^\d+(\s*(rb|ribu|k|jt|juta))?$/iu', $candidate)) {
            return null;
        }

        return ['action' => 'category', 'value' => $candidate];
    }

    /** Validasi nominal dari LLM: integer positif dalam rentang wajar. */
    protected function validateLlmAmount(mixed $value): ?array
    {
        $amount = is_numeric($value) ? (int) $value : 0;

        if ($amount <= 0 || $amount > 10_000_000_000) {
            return null;
        }

        return ['action' => 'amount', 'value' => $amount];
    }

    /** Validasi tanggal dari LLM: format ISO, tidak di masa depan (> 1 hari). */
    protected function validateLlmDate(mixed $value): ?array
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($date->lt(now()->subYears(2)) || $date->gt(now()->addDay()->startOfDay())) {
            return null;
        }

        return ['action' => 'date', 'value' => $date->toDateString()];
    }

    /**
     * Deteksi perintah koreksi kategori dalam balasan.
     * "Ubah kategori nya jadi bahan makanan", "ganti kategori ke makanan".
     *
     * @return string|null nama kategori tujuan, atau null bila bukan perintah ubah kategori
     */
    protected function extractCategoryCandidate(?string $text): ?string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        $match = null;
        $patterns = [
            // "ubah kategori nya jadi X" / "ubah kategorinya jadi X" / "ganti kategori ke X"
            '/\bkategori(?:nya| nyaa)?\b[^?!.]*?\b(?:jadi|ke|menjadi)\s+(.+)$/iu',
            // "ubah kategori X" / "ubah kategorinya X" (tanpa kata penghubung)
            '/\b(?:ubah|ganti|rubah|pindah(?:in)?|revisi)\s+(?:\S+\s+){0,2}kategori(?:nya)?\s+(?:jadi\s+|ke\s+)?(.+)$/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                break;
            }
        }

        if (! $match) {
            return null;
        }

        $candidate = TransactionService::cleanCategoryCandidate(trim($match[1]));

        // Tolak kosong / terpendek / angka bernominal ("25rb") — itu edit nominal, bukan kategori.
        if ($candidate === ''
            || mb_strlen($candidate) < 2
            || preg_match('/^\d+(\s*(rb|ribu|k|jt|juta))?$/iu', $candidate)) {
            return null;
        }

        return $candidate;
    }

    /**
     * Fase 3 — balasan berupa perintah hapus
     */
    protected function isDeleteCommand(?string $text): bool
    {
        if (! $text) {
            return false;
        }

        return (bool) preg_match(
            '/^\s*(?:hapus|delete|remove|buang|tapus|del)[.! ]*$/iu',
            trim($text)
        );
    }

    /**
     * Fase 3 — alur konfirmasi hapus.
     */
    protected function startDeleteFlow(Transaction $transaction, array $replyContext, string $resolveMethod): void
    {
        Cache::put(
            $this->confirmationStateKey(),
            [
                'mode' => 'confirm_delete',
                'transaction_id' => $transaction->id,
                'old_amount' => (int) $transaction->amount,
                'type' => $transaction->type,
                'resolve_method' => $resolveMethod,
                'wa_message_id' => $replyContext['messageId'] ?? null,
                'expires_at' => now()->addSeconds(config('finwa_reply_edit.confirmation_ttl', 300))->timestamp,
            ],
            now()->addSeconds(config('finwa_reply_edit.confirmation_ttl', 300))
        );

        [$typeEmoji, $typeLabel] = match ($transaction->type) {
            'income' => ['💰', 'Pemasukan'],
            'expense' => ['💸', 'Pengeluaran'],
            default => ['🔄', 'Pindah saldo'],
        };
        $this->sendReply(
            "🗑️ *Hapus transaksi ini?*\n\n".
            "{$typeEmoji} *{$typeLabel}* · Rp ".number_format((int) $transaction->amount, 0, ',', '.')."\n".
            "📝 ".(KeywordNormalizer::stripAmountFromDescription($transaction->description) ?: 'Transaksi')."\n".
            "📅 ".$transaction->transaction_date->translatedFormat('d M Y')."\n\n".
            "Balas *Ya* untuk hapus, *Batal* untuk batal.\n".
            '_Salah hapus bisa dipulihkan dengan *kembalikan* (30 hari)._'
        );
    }

    /**
     * Fase 3 — ekstrak tanggal edit dari balasan.
     *
     * Mendukung: "tgl 21/09/2026", "tanggal 21-09-2026", "21/09/2026"
     * (versi tanpa kata kunci dianggap bukan tanggal → tolak, cegah false positive).
     */
    protected function extractDate(?string $text): ?\Illuminate\Support\Carbon
    {
        if (! $text) {
            return null;
        }

        if (preg_match('/\b(?:tgl|tanggal|date)\s*:?\s*(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})\b/u', $text, $m)) {
            return $this->makeSafeDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/\b(?:tgl|tanggal|date)\s*:?\s*(\d{1,2})\s*([a-z]{3,})\s*(\d{4})?/iu', $text, $m)) {
            $month = $this->monthFromName($m[2]);
            if ($month !== null) {
                $year = ! empty($m[3]) ? (int) $m[3] : (int) now()->year;

                return $this->makeSafeDate((int) $m[1], $month, $year);
            }
        }

        return null;
    }

    protected function makeSafeDate(int $day, int $month, int $year): ?\Illuminate\Support\Carbon
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $year < 2000 || $year > 2100) {
            return null;
        }

        try {
            $date = \Illuminate\Support\Carbon::create($year, $month, $day, 0, 0, 0);
        } catch (\Throwable) {
            return null;
        }

        // Tolak tanggal jauh di masa depan (> 1 hari) — konsisten dgn Bug #1
        if ($date->gt(now()->addDay()->startOfDay())) {
            return null;
        }

        return $date;
    }

    protected function monthFromName(string $name): ?int
    {
        $map = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'jun' => 6,
            'jul' => 7, 'agu' => 8, 'agt' => 8, 'sep' => 9, 'okt' => 10, 'nov' => 11, 'des' => 12,
            'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4, 'juni' => 6,
            'juli' => 7, 'agustus' => 8, 'september' => 9, 'oktober' => 10,
            'november' => 11, 'desember' => 12,
        ];

        return $map[mb_strtolower($name)] ?? null;
    }

    /**
     * Tahap 2: handle balasan Ya / Batal / timeout / pesan lain.
     */
    protected function handleConfirmationResponse(array $state): void
    {
        $text = trim(mb_strtolower($this->message->content ?? ''));

        // Timeout
        if (now()->timestamp > (int) ($state['expires_at'] ?? 0)) {
            Cache::forget($this->confirmationStateKey());
            $this->sendReply('⏳ Konfirmasi edit kedaluwarsa. Balas pesan transaksi lagi untuk mengulang.');

            return;
        }

        if (preg_match('/^(ya|y|yes|oke|ok|siap|banget|simpan|lanjut)[.! ]*$/u', $text)) {
            if (config('finwa_reply_edit.dry_run', true)) {
                Cache::forget($this->confirmationStateKey());
                Log::info('[reply-edit dry-run] update dibatalkan (flag aktif)', $state);
                $this->sendReply('✅ (dry-run) Perubahan tercatat di log — belum diterapkan ke database.');

                return;
            }

            $this->applyEdit($state);

            return;
        }

        if (preg_match('/^(batal|tidak|tidak jadi|no|cancel|stop)[.! ]*$/u', $text)) {
            Cache::forget($this->confirmationStateKey());
            $this->sendReply('👌 Oke, perubahan dibatalkan.');

            return;
        }

        // Ops — perintah ubah kategori saat konfirmasi edit pending:
        // terapkan ke transaksi di state & lepas konfirmasi (user beralih perintah).
        // Frasa bebas lewat LLM bila regex tidak menangkap.
        $command = $this->parseEditCommand($this->message->content ?? '');
        if ($command !== null && $command['action'] === 'category' && $this->categoryCorrectionCallback !== null) {
            $transaction = Transaction::where('tenant_id', $this->message->tenant_id)
                ->where('id', $state['transaction_id'] ?? 0)
                ->first();

            if ($transaction) {
                Cache::forget($this->confirmationStateKey());
                call_user_func($this->categoryCorrectionCallback, $command['value'], $transaction);

                return;
            }
        }

        // Balasan tidak dikenal — ulangi prompt
        $this->sendReply('Konfirmasi edit transaksi:\nBalas *Ya* untuk simpan, *Batal* untuk batalkan.');
    }

    /**
     * Fase 3 — handle balasan konfirmasi hapus (Ya / Batal / timeout).
     */
    protected function handleDeleteResponse(array $state): void
    {
        $text = trim(mb_strtolower($this->message->content ?? ''));

        if (now()->timestamp > (int) ($state['expires_at'] ?? 0)) {
            Cache::forget($this->confirmationStateKey());
            $this->sendReply('⏳ Konfirmasi kedaluwarsa. Balas pesan transaksi lagi untuk mengulang.');

            return;
        }

        if (preg_match('/^(ya|y|yes|oke|ok|siap|hapus|lanjut)[.! ]*$/u', $text)) {
            if (config('finwa_reply_edit.dry_run', true)) {
                Cache::forget($this->confirmationStateKey());
                Log::info('[reply-edit dry-run] hapus dibatalkan (flag aktif)', $state);
                $this->sendReply('✅ (dry-run) Perintah hapus tercatat di log — belum diterapkan.');

                return;
            }

            $this->applyDelete($state);

            return;
        }

        if (preg_match('/^(batal|tidak|tidak jadi|no|cancel|stop)[.! ]*$/u', $text)) {
            Cache::forget($this->confirmationStateKey());
            $this->sendReply('👌 Oke, transaksi tidak jadi dihapus.');

            return;
        }

        $this->sendReply('Konfirmasi hapus transaksi:\nBalas *Ya* untuk hapus, *Batal* untuk batal.');
    }

    /**
     * Fase 3 — eksekusi hapus dalam satu transaksi DB: saldo kembali + audit.
     */
    protected function applyDelete(array $state): void
    {
        try {
            DB::transaction(function () use ($state) {
                $transaction = Transaction::where('tenant_id', $this->message->tenant_id)
                    ->where('id', $state['transaction_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $transaction) {
                    throw new \RuntimeException('Transaksi tidak ditemukan');
                }

                DB::table('transaction_edit_audits')->insert([
                    'tenant_id' => $this->message->tenant_id,
                    'transaction_id' => $transaction->id,
                    'actor_user_id' => null,
                    'old_values' => json_encode([
                        'amount' => (int) $transaction->amount,
                        'type' => $transaction->type,
                        'description' => $transaction->description,
                        'transaction_date' => $transaction->transaction_date->toDateString(),
                    ]),
                    'new_values' => json_encode(['deleted' => true]),
                    'resolve_method' => $state['resolve_method'] ?? 'mapping',
                    'wa_message_id' => $state['wa_message_id'] ?? null,
                    'created_at' => now(),
                ]);

                // Hapus pemetaan agar reply berikutnya tidak menunjuk baris mati
                DB::table('transaction_message_mappings')
                    ->where('tenant_id', $this->message->tenant_id)
                    ->where('transaction_id', $transaction->id)
                    ->delete();

                // Soft delete + kembalikan saldo (TransactionLedger; pasangan transfer ikut)
                app(\App\Services\Ledger\TransactionLedger::class)->delete($transaction);
            });
        } catch (\Exception $e) {
            Log::error('Reply-edit: gagal hapus transaksi', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);
            $this->sendReply("⚠️ *Gagal menghapus transaksi*\n\nTerjadi kesalahan. Silakan coba lagi.");

            return;
        }

        Cache::forget($this->confirmationStateKey());

        $this->sendReply(
            "✅ *Transaksi dihapus.*\n\n".
            "• Nominal: Rp ".number_format((int) $state['old_amount'], 0, ',', '.')."\n".
            "Saldo telah disesuaikan.\n\n".
            '↩️ Salah hapus? Ketik *kembalikan* (berlaku 30 hari).'
        );
    }

    /**
     * Eksekusi update dalam satu transaksi DB: nominal + tanggal + saldo + audit + hapus state.
     */
    protected function applyEdit(array $state): void
    {
        try {
            DB::transaction(function () use ($state) {
                $transaction = Transaction::where('tenant_id', $this->message->tenant_id)
                    ->where('id', $state['transaction_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $transaction) {
                    throw new \RuntimeException('Transaksi tidak ditemukan');
                }

                $oldAmount = (int) $transaction->amount;
                $oldDate = $transaction->transaction_date->toDateString();
                $newAmount = (int) $state['new_amount'];
                $newDate = $state['new_date'] ?? null;
                $diff = $newAmount - $oldAmount;

                // Nominal/tanggal + saldo (TransactionLedger; pasangan transfer ikut)
                $changes = ['amount' => $newAmount];
                if ($newDate) {
                    $changes['transaction_date'] = $newDate;
                }
                app(\App\Services\Ledger\TransactionLedger::class)->update($transaction, $changes);

                // Audit trail
                $oldValues = ['amount' => $oldAmount];
                $newValues = ['amount' => $newAmount];
                if ($newDate && $newDate !== $oldDate) {
                    $oldValues['transaction_date'] = $oldDate;
                    $newValues['transaction_date'] = $newDate;
                }

                DB::table('transaction_edit_audits')->insert([
                    'tenant_id' => $this->message->tenant_id,
                    'transaction_id' => $transaction->id,
                    'actor_user_id' => null,
                    'old_values' => json_encode($oldValues),
                    'new_values' => json_encode($newValues),
                    'resolve_method' => $state['resolve_method'] ?? 'mapping',
                    'wa_message_id' => $state['wa_message_id'] ?? null,
                    'created_at' => now(),
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Reply-edit: gagal eksekusi update', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
            ]);
            $this->sendReply("⚠️ *Gagal mengubah transaksi*\n\nTerjadi kesalahan. Silakan coba lagi.");

            return;
        }

        Cache::forget($this->confirmationStateKey());

        // Pasang pemetaan langsung (bila reply_edit aktif), agar balasan
        // berikutnya ke pesan ini langsung terkena prioritas 1 (mapping).
        if (config('finwa_reply_edit.enabled', false) && ! empty($state['wa_message_id'])) {
            self::storeMapping(
                (int) $this->message->tenant_id,
                null,
                (string) $state['wa_message_id'],
                (int) $state['transaction_id'],
                $this->message->sender_id
            );
        }

        $transaction = Transaction::where('id', $state['transaction_id'])->first();

        $summary = [];
        if ((int) $state['old_amount'] !== (int) $state['new_amount']) {
            $summary[] = '• Nominal: Rp '.number_format((int) $state['old_amount'], 0, ',', '.')
                .' → Rp '.number_format((int) $state['new_amount'], 0, ',', '.');
        }
        if (! empty($state['new_date']) && $state['new_date'] !== ($state['old_date'] ?? null)) {
            $summary[] = '• Tanggal: '.\Illuminate\Support\Carbon::parse($state['old_date'])->translatedFormat('d M Y')
                .' → '.\Illuminate\Support\Carbon::parse($state['new_date'])->translatedFormat('d M Y');
        }

        $this->sendReply(
            "✅ *Transaksi Berhasil Diedit!*\n\n".
            implode("\n", $summary)."\n\n".
            "*Transaksi sekarang:*\n".
            ($transaction->type === 'income' ? '💰' : '💸').' Rp '.number_format($transaction->amount, 0, ',', '.')."\n".
            '📝 '.(KeywordNormalizer::stripAmountFromDescription($transaction->description) ?: '-')."\n".
            '📅 '.$transaction->transaction_date->translatedFormat('d M Y')
        );
    }

    /**
     * Ekstrak nominal dari teks balasan: "25rb", "25.000", "25000", "250k".
     */
    protected function extractAmount(?string $text): ?int
    {
        if (! $text) {
            return null;
        }

        // Angka bagian dari tanggal bukan nominal — buang dulu supaya tidak
        // tertangkap pola \d{4,} ("tgl 21/09/2026" jangan jadi amount 2026).
        $scan = (string) preg_replace('/\b\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{4}\b/', ' ', \App\Support\AmountParser::normalize($text));
        $scan = (string) preg_replace(
            '/\b(?:tgl|tanggal|date)\s*:?\s*\d{1,2}\s+\w{3,}\s+(?:19|20)\d{2}\b/iu',
            ' ',
            $scan
        );

        if (($composite = \App\Support\AmountParser::composite($scan)) !== null) {
            return $composite;
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(rb|ribu|k|jt|juta)/iu', $scan, $m)) {
            $num = (float) str_replace(',', '.', $m[1]);
            $multiplier = in_array(strtolower($m[2]), ['jt', 'juta'], true) ? 1000000 : 1000;

            return (int) ($num * $multiplier);
        }

        if (preg_match('/rp\.?\s*([\d.,]+)/iu', $scan, $m)) {
            return (int) str_replace('.', '', str_replace(',', '', $m[1]));
        }

        if (preg_match('/\b(\d{1,3}(?:\.\d{3})+)\b/', $scan, $m)) {
            return (int) str_replace('.', '', $m[1]);
        }

        if (preg_match('/\b(\d{4,})\b/', $scan, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    protected function confirmationStateKey(): string
    {
        // Identitas tetap (LID ↔ nomor HP orang yang sama → sama)
        return 'finwa_reply_edit_state:'.$this->message->tenant_id.':'
            .\App\Support\SenderIdentity::canonical((int) $this->message->tenant_id, (string) $this->message->sender_id);
    }

    protected function isDryRun(): bool
    {
        return (bool) config('finwa_reply_edit.dry_run', true);
    }

    /**
     * Fase 1c — simpan pemetaan wa_message_id pesan balasan Finwa → transaksi.
     * Dipanggil dari TransactionConfirmationService setelah konfirmasi terkirim.
     */
    public static function storeMapping(int $tenantId, ?int $userId, string $waMessageId, int $transactionId, ?string $waSender = null): void
    {
        try {
            DB::table('transaction_message_mappings')->updateOrInsert(
                ['tenant_id' => $tenantId, 'wa_message_id' => $waMessageId],
                [
                    'user_id' => $userId,
                    'transaction_id' => $transactionId,
                    'wa_sender' => $waSender,
                    'created_at' => now(),
                ]
            );
        } catch (\Exception $e) {
            Log::warning('Reply-edit: gagat simpan mapping', [
                'wa_message_id' => $waMessageId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
