<?php

namespace App\Console\Commands;

use App\Models\QuickSale;
use Illuminate\Console\Command;

class ActivateQuickSalesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'quicksales:activate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Activate approved quick sales whose start time has passed';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = now();

        $toActivate = QuickSale::where('status', QuickSale::STATUS_APPROVED)
            ->whereNotNull('start_time')
            ->where('start_time', '<=', $now)
            ->where('end_time', '>', $now)
            ->get();

        $activatedCount = 0;

        foreach ($toActivate as $quickSale) {
            if ($quickSale->shouldBeActive()) {
                $quickSale->markAsActive();
                $activatedCount++;
            }
        }

        $this->info("Activated {$activatedCount} quick sales.");

        return Command::SUCCESS;
    }
}
