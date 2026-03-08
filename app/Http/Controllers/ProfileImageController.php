<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileImageController extends Controller
{
    /**
     * Serve a profile image from the configured profile_image_disk (R2, public, etc.)
     * so that URLs use the app domain (e.g. posbackend-main-a1gh7m.laravel.cloud).
     */
    public function show(string $filename): StreamedResponse|\Illuminate\Http\Response
    {
        $path = 'profile_images/'.$filename;
        $disk = config('filesystems.profile_image_disk', 'public');

        if (! Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        $mimeType = Storage::disk($disk)->mimeType($path) ?: 'image/jpeg';

        return Storage::disk($disk)->response($path, $filename, [
            'Content-Type' => $mimeType,
        ]);
    }
}
