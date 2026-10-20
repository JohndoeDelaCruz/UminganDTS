<?php

use App\Http\Controllers\Admin\ConfigurationController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\OfficeWorkController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\PublicTrackingController;
use App\Http\Controllers\StaffNotificationController;
use App\Http\Controllers\WorkspaceActivityController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthenticatedSessionController::class, 'home'])->name('home');
Route::get('/track', [PublicTrackingController::class, 'index'])->name('track');
Route::post('/track', [PublicTrackingController::class, 'lookup'])->middleware('throttle:public-tracking')->name('track.lookup');

Route::middleware('auth')->group(function () {
    Route::get('/workspace/activity', WorkspaceActivityController::class)->name('workspace.activity');
    Route::get('/workspace/notifications', [StaffNotificationController::class, 'index'])->name('notifications.index');
    Route::post('/workspace/notifications/read', [StaffNotificationController::class, 'read'])->name('notifications.read');
    Route::post('/workspace/notifications/{notification}/open', [StaffNotificationController::class, 'open'])->whereNumber('notification')->name('notifications.open');
    Route::get('/dashboard', [OverviewController::class, 'dashboard'])->name('dashboard');
    Route::get('/my-work', [OfficeWorkController::class, 'index'])->name('my-work');
    Route::post('/documents/{document}/office-work', [OfficeWorkController::class, 'store'])->name('documents.office-work');
    Route::get('/reports', [OverviewController::class, 'reports'])->name('reports');
    Route::get('/reports/export', [OverviewController::class, 'export'])->middleware('throttle:10,1')->name('reports.export');
    Route::get('/inbox', [DocumentController::class, 'index'])->name('inbox');
    Route::get('/outgoing', [DocumentController::class, 'index'])->name('outgoing');
    Route::resource('documents', DocumentController::class)->only(['index', 'create', 'store', 'show']);
    Route::post('/documents/{document}/forward', [DocumentController::class, 'forward'])->name('documents.forward');
    Route::post('/documents/{document}/return', [DocumentController::class, 'returnForCorrection'])->name('documents.return');
    Route::post('/documents/{document}/transfers/{transfer}', [DocumentController::class, 'transfer'])->name('documents.transfer');
    Route::post('/documents/{document}/status', [DocumentController::class, 'status'])->name('documents.status');
    Route::post('/documents/{document}/remarks', [DocumentController::class, 'remark'])->name('documents.remark');
    Route::post('/documents/{document}/public-credential', [DocumentController::class, 'publicCredential'])->name('documents.public-credential');
    Route::post('/documents/{document}/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])->name('attachments.download');
    Route::middleware('can:admin')->group(function () {
        Route::get('/departments', [ConfigurationController::class, 'departments'])->name('departments');
        Route::post('/departments', [ConfigurationController::class, 'saveDepartment'])->name('departments.store');
        Route::put('/departments/{department}', [ConfigurationController::class, 'saveDepartment'])->name('departments.update');
        Route::get('/settings/categories', [ConfigurationController::class, 'types'])->name('types');
        Route::post('/settings/categories', [ConfigurationController::class, 'saveType'])->name('types.store');
        Route::put('/settings/categories/{type}', [ConfigurationController::class, 'saveType'])->name('types.update');
        Route::get('/users', [ConfigurationController::class, 'users'])->name('users');
        Route::post('/users', [ConfigurationController::class, 'saveUser'])->name('users.store');
        Route::put('/users/{user}', [ConfigurationController::class, 'saveUser'])->name('users.update');
        Route::get('/audit', [ConfigurationController::class, 'audit'])->name('audit');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
