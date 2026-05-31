<?php

use App\Http\Controllers\EventAttachmentDownloadController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/alerts/{event}/attachments/{attachment}/download', EventAttachmentDownloadController::class)
        ->name('alerts.attachments.download');
});
