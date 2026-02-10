<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ServerSync extends Command
{
    protected $signature = 'server:sync
                          {--push : Only push local changes to cloud}
                          {--pull : Only pull cloud changes to local}
                          {--force : Force sync even if recently synced}';

    protected $description = 'Sync data between local/edge server and cloud server';

    public function handle()
    {
        $mode = config('sync.mode');

        if ($mode === 'standalone') {
            $this->warn('Server is in standalone mode. Server-to-server sync is disabled.');
            return Command::FAILURE;
        }

        if ($mode !== 'edge') {
            $this->warn('This command should only run on edge servers.');
            return Command::FAILURE;
        }

        $this->info('Starting server synchronization...');
        $this->newLine();

        $pushOnly = $this->option('push');
        $pullOnly = $this->option('pull');

        try {
            // Check cloud server availability
            $this->info('⏳ Checking cloud server status...');
            if (!$this->checkCloudServer()) {
                $this->error('❌ Cloud server is unreachable. Working in offline mode.');
                return Command::FAILURE;
            }
            $this->info('✅ Cloud server is online');
            $this->newLine();

            // Push local changes
            if (!$pullOnly) {
                $this->info('⏳ Pushing local changes to cloud...');
                $pushResult = $this->pushToCloud();
                
                if ($pushResult['status'] === 'success') {
                    $this->info("✅ Push completed: {$pushResult['message']}");
                } else {
                    $this->error("❌ Push failed: {$pushResult['message']}");
                }
                $this->newLine();
            }

            // Pull cloud changes
            if (!$pushOnly) {
                $this->info('⏳ Pulling changes from cloud...');
                $pullResult = $this->pullFromCloud();
                
                if ($pullResult['status'] === 'success') {
                    $this->info("✅ Pull completed: {$pullResult['message']}");
                } else {
                    $this->error("❌ Pull failed: {$pullResult['message']}");
                }
                $this->newLine();
            }

            $this->info('🎉 Synchronization complete!');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Synchronization failed: ' . $e->getMessage());
            Log::error('Server sync failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }
    }

    private function checkCloudServer()
    {
        try {
            $cloudUrl = config('sync.cloud_server_url');
            $response = Http::timeout(5)->get("{$cloudUrl}/server-sync/health");
            
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    private function pushToCloud()
    {
        try {
            $localUrl = config('sync.local_server_url');
            $response = Http::timeout(30)->post("{$localUrl}/server-sync/push");
            
            if ($response->successful()) {
                $data = $response->json();
                return [
                    'status' => 'success',
                    'message' => $data['message'] ?? 'Changes pushed successfully'
                ];
            }

            return [
                'status' => 'error',
                'message' => 'Push request failed with status ' . $response->status()
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    private function pullFromCloud()
    {
        try {
            $localUrl = config('sync.local_server_url');
            $response = Http::timeout(30)->post("{$localUrl}/server-sync/pull");
            
            if ($response->successful()) {
                $data = $response->json();
                $applied = $data['changes_applied'] ?? 0;
                
                return [
                    'status' => 'success',
                    'message' => "Applied {$applied} changes from cloud"
                ];
            }

            return [
                'status' => 'error',
                'message' => 'Pull request failed with status ' . $response->status()
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
