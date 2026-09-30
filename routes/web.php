<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LessonController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('lernseiten/neu', [LessonController::class, 'create'])->name('lessons.create');
    Route::post('lernseiten', [LessonController::class, 'store'])->name('lessons.store');
    Route::get('lernseiten/{lesson}', [LessonController::class, 'show'])->name('lessons.show');
    Route::post('lernseiten/{lesson}/nochmals', [LessonController::class, 'retry'])->name('lessons.retry');
});

Route::get('lernseiten/{lesson}/grafik', [LessonController::class, 'hero'])
    ->middleware('signed')
    ->name('lessons.hero');

if (app()->isLocal()) {
    Route::get('vorschau/lernseiten/{lesson}', [LessonController::class, 'preview'])->name('lessons.preview');
}

require __DIR__.'/settings.php';
