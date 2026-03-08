<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProfileImageService
{
    private const DIRECTORY = 'profile_images';

    private static function disk(): string
    {
        return config('filesystems.profile_image_disk', 'public');
    }

    /**
     * Store an uploaded profile image and return the path to save on the user.
     * Uses the disk from config (default: public). Set PROFILE_IMAGE_DISK=s3 and
     * AWS_* env vars to store in a cloud bucket.
     */
    public function store(UploadedFile $file): string
    {
        $path = $file->store(
            self::DIRECTORY,
            [
                'disk' => self::disk(),
                'visibility' => 'public',
            ]
        );

        return $path;
    }

    /**
     * Delete a previously stored profile image by path.
     */
    public function delete(?string $path): void
    {
        if ($path && Storage::disk(self::disk())->exists($path)) {
            Storage::disk(self::disk())->delete($path);
        }
    }

    /**
     * Replace: delete old image if present, store new file, return new path.
     */
    public function replace(?string $oldPath, UploadedFile $file): string
    {
        $this->delete($oldPath);

        return $this->store($file);
    }
}
