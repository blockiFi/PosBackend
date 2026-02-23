<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class SeedRunCommand extends Command
{
    protected $signature = 'seed:run
                            {--size=large : Seed size: small (fast, less data) or large (full demo)}';

    protected $description = 'Run database seed with optional size (small for fast/remote, large for full data)';

    public function handle(): int
    {
        $size = $this->option('size');
        if (! in_array($size, ['small', 'large'], true)) {
            $this->error('Size must be "small" or "large".');

            return 1;
        }

        Config::set('seeding.size', $size);
        $this->info("Seeding with size: {$size}");
        $this->newLine();

        $this->call('db:seed');

        return 0;
    }
}
