<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProfileImageService
{
    private const DISK = 'public';

    private const DIRECTORY = 'profile_images';

    /**
     * Store an uploaded profile image and return the path to save on the user.
     */
    public function store(UploadedFile $file): string
    {
        $path = $file->store(
            self::DIRECTORY,
            [
                'disk' => self::DISK,
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
        if ($path && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
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
