<?php

use App\Http\Controllers\EventAttachmentDownloadController;
use App\Http\Controllers\SecurityContainerAttachmentDownloadController;
use App\Http\Controllers\SoftwareAssetAttachmentDownloadController;
use App\Http\Controllers\SoftwareAssetSbomZipDownloadController;
use App\Http\Controllers\SoftwareSystemAttachmentDownloadController;
use App\Http\Controllers\SoftwareSystemSbomZipDownloadController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/alerts/{event}/attachments/{attachment}/download', EventAttachmentDownloadController::class)
        ->name('alerts.attachments.download');
    Route::get('/assets/{asset}/attachments/{attachment}/download', SoftwareAssetAttachmentDownloadController::class)
        ->name('assets.attachments.download');
    Route::get('/software-systems/{system}/attachments/{attachment}/download', SoftwareSystemAttachmentDownloadController::class)
        ->name('software-systems.attachments.download');
    Route::get('/security-containers/{container}/attachments/{attachment}/download', SecurityContainerAttachmentDownloadController::class)
        ->name('security-containers.attachments.download');
    Route::get('/assets/{asset}/sbom.zip', SoftwareAssetSbomZipDownloadController::class)
        ->name('assets.sbom.download');
    Route::get('/software-systems/{system}/sbom.zip', SoftwareSystemSbomZipDownloadController::class)
        ->name('software-systems.sbom.download');
});
