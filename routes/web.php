<?php

use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\ProfileImageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/docs/{file}', [DocumentationController::class, 'show'])->where('file', '.*');

// Profile images served from app URL (e.g. posbackend-main-a1gh7m.laravel.cloud/profile-images/xxx.jpg)
Route::get('/profile-images/{filename}', [ProfileImageController::class, 'show'])
    ->where('filename', '[a-zA-Z0-9._-]+')
    ->name('profile-images.show');
