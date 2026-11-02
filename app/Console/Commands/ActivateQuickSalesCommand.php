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
    protected $description = 'Activate approved quick sales whose start time has passed and end those past their end time';

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

        // End promotions whose end time has passed, so their discount comes
        // off the item now (and tills pick that up) instead of at the 1am
        // daily cleanup
        $toExpire = QuickSale::where('status', QuickSale::STATUS_ACTIVE)
            ->whereNotNull('end_time')
            ->where('end_time', '<=', $now)
            ->get();

        foreach ($toExpire as $quickSale) {
            $quickSale->markAsExpired();
        }

        $this->info("Activated {$activatedCount} quick sales, ended {$toExpire->count()}.");

        return Command::SUCCESS;
    }
}
