<?php

use App\Http\Controllers\EventAttachmentDownloadController;
use App\Http\Controllers\TwoFactorSetupController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/alerts/{event}/attachments/{attachment}/download', EventAttachmentDownloadController::class)
        ->name('alerts.attachments.download');
    Route::get('/user/two-factor-setup', [TwoFactorSetupController::class, 'show'])
        ->name('two-factor.setup');
    Route::post('/user/two-factor-setup/confirm', [TwoFactorSetupController::class, 'confirm'])
        ->name('two-factor.setup.confirm');
    Route::get('/user/two-factor-setup/recovery-codes', [TwoFactorSetupController::class, 'recoveryCodes'])
        ->name('two-factor.setup.recovery-codes');
});
