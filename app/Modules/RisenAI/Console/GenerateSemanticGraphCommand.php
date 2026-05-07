<?php

namespace App\Modules\RisenAI\Console;

use App\Modules\RisenAI\Models\SeoKeywordCluster;
use App\Modules\RisenAI\Services\SemanticGraphService;
use Illuminate\Console\Command;

class GenerateSemanticGraphCommand extends Command
{
    protected $signature = 'risen-ai:semantic-graph {--pillars=}';

    protected $description = 'Generate Semantic Topic Graph';

    public function handle(SemanticGraphService $service)
    {
        $pillars = explode(',', $this->option('pillars'));
        $clusters = SeoKeywordCluster::all()->toArray();

        $this->info('Building Semantic Graph...');
        $result = $service->build($clusters, $pillars);

        $this->info('Semantic Graph build complete.');

        return 0;
    }
}
