<?php

namespace App\Modules\RisenAI\Console;

use App\Modules\RisenAI\Services\DataLakeService;
use Illuminate\Console\Command;

class GenerateDataLakeCommand extends Command
{
    protected $signature = 'risen-ai:data-lake {--keywords=} {--locations=} {--professions=}';

    protected $description = 'Generate Data Lake from seed keywords';

    public function handle(DataLakeService $service)
    {
        $keywords = explode(',', $this->option('keywords'));
        $locations = explode(',', $this->option('locations'));
        $professions = $this->option('professions') ? explode(',', $this->option('professions')) : [];

        $this->info('Building Data Lake...');
        $result = $service->build([
            'seed_keywords' => $keywords,
            'locations' => $locations,
            'professions' => $professions,
        ]);

        $this->info('Data Lake build complete.');

        return 0;
    }
}
