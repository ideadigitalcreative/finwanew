<?php

namespace App\Modules\RisenAI\Jobs;

use App\Modules\RisenAI\Services\IntentMatcherService;
use App\Modules\RisenAI\Services\PageGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GeneratePageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

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

        if ($autoPublish && ($audit['intent_match_score'] ?? 0) >= $minScore) {
            $page->update(['status' => 'published']);
        }
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('RisenAI: GeneratePageJob failed', [
            'params' => $this->params,
            'message' => $exception->getMessage(),
        ]);
    }
}
