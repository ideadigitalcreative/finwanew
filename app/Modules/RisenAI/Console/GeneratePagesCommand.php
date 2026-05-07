<?php

namespace App\Modules\RisenAI\Console;

use App\Modules\RisenAI\Jobs\GeneratePageJob;
use Illuminate\Console\Command;

class GeneratePagesCommand extends Command
{
    protected $signature = 'risen-ai:generate-pages 
                            {--intent=informational} 
                            {--service=} 
                            {--locations=} 
                            {--professions=} 
                            {--batch=1}';

    protected $description = 'Generate SEO pages via AI';

    public function handle()
    {
        $intent = $this->option('intent');
        $service = $this->option('service');
        $batch = (int) $this->option('batch');
        $locations = $this->option('locations') ? explode(',', $this->option('locations')) : [null];
        $professions = $this->option('professions') ? explode(',', $this->option('professions')) : [null];

        if (! $service) {
            $this->error('Service/Topic is required');

            return 1;
        }

        $this->info("Enqueuing {$batch} pages for '{$service}' with intent '{$intent}'...");

        foreach ($locations as $location) {
            foreach ($professions as $profession) {
                for ($i = 0; $i < $batch; $i++) {
                    GeneratePageJob::dispatch([
                        'intent' => $intent,
                        'service' => $service,
                        'location' => $location,
                        'profession' => $profession,
                    ])->onQueue(config('risen-ai.queue_name'));
                }
            }
        }

        $this->info('Done. Check queue worker for progress.');
    }
}
