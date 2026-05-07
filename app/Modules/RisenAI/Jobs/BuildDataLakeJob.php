<?php

namespace App\Modules\RisenAI\Jobs;

use App\Modules\RisenAI\Services\DataLakeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BuildDataLakeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        private array $input
    ) {}

    public function handle(DataLakeService $service): void
    {
        $service->build($this->input);
    }
}
