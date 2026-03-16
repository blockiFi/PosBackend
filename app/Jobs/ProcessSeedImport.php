<?php

namespace App\Jobs;

use App\Models\SeedImport;
use App\Services\SeedFromFileService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessSeedImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public SeedImport $seedImport) {}

    public function handle(SeedFromFileService $service): void
    {
        $import = $this->seedImport;
        $import->markProcessing();

        try {
            $filePath = Storage::disk('local')->path($import->file_path);

            if (! file_exists($filePath)) {
                $import->markFailed('Uploaded file no longer exists.');

                return;
            }

            $file = new UploadedFile(
                $filePath,
                basename($filePath),
                mime_content_type($filePath) ?: null,
                null,
                true
            );

            $result = $service->run(
                $file,
                $import->entity,
                $import->mapping,
                $import->unique_key,
                $import->business_id,
                $import->branch_id,
                $import->delete,
                $import
            );

            $import->markCompleted($result);
        } catch (\Throwable $e) {
            $import->markFailed($e->getMessage());
        } finally {
            Storage::disk('local')->delete($import->file_path);
        }
    }
}
