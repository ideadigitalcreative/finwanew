<?php

namespace App\Console\Commands;

use App\Services\Budget\BudgetRolloverService;
use Illuminate\Console\Command;

class ProcessBudgetRollover extends Command
{
    protected $signature = 'budget:rollover';

    protected $description = 'Process budget rollovers: carry leftover amounts to next period for eligible budgets';

    public function handle(): int
    {
        $service = new BudgetRolloverService();
        $result = $service->processRollovers();

        $this->info("Budget rollover processed: {$result['processed']} budgets");
        $this->info('Total rollover: Rp ' . number_format($result['total_rollover'], 0, ',', '.'));

        return Command::SUCCESS;
    }
}
