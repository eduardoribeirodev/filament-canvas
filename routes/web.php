<?php

use EduardoRibeiroDev\FilamentCanvas\Http\Controllers\MediaFileController;
use Illuminate\Support\Facades\Route;

Route::get('/filament-canvas/files/{file}', MediaFileController::class)
    ->middleware(['web', 'signed'])->name('filament-canvas.file');
