<?php

use App\Http\Controllers\ChildController;
use App\Http\Controllers\CostController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LessonContentController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\SharedLessonController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
Route::inertia('datenschutz', 'Privacy')->name('privacy');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('kosten', CostController::class)->name('costs');

    Route::get('kinder', [ChildController::class, 'index'])->name('children.index');
    Route::post('kinder', [ChildController::class, 'store'])->name('children.store');
    Route::patch('kinder/{child}', [ChildController::class, 'update'])->name('children.update');
    Route::delete('kinder/{child}', [ChildController::class, 'destroy'])->name('children.destroy');
    Route::post('kinder/{child}/neuer-link', [ChildController::class, 'renewLink'])->name('children.renew-link');
    Route::get('kinder/{child}/lernstand', [ChildController::class, 'progress'])->name('children.progress');

    Route::get('lernseiten/neu', [LessonController::class, 'create'])->name('lessons.create');
    Route::post('lernseiten', [LessonController::class, 'store'])->name('lessons.store');
    Route::get('lernseiten/{lesson}', [LessonController::class, 'show'])->name('lessons.show');
    Route::delete('lernseiten/{lesson}', [LessonController::class, 'destroy'])->name('lessons.destroy');
    Route::post('lernseiten/{lesson}/nochmals', [LessonController::class, 'retry'])->name('lessons.retry');
    Route::post('lernseiten/{lesson}/freigeben', [LessonController::class, 'publish'])->name('lessons.publish');
    Route::post('lernseiten/{lesson}/zurueckziehen', [LessonController::class, 'unpublish'])->name('lessons.unpublish');
    Route::post('lernseiten/{lesson}/neu/{part}', [LessonController::class, 'regenerate'])
        ->whereIn('part', ['quiz', 'grafik'])
        ->name('lessons.regenerate');

    Route::get('lernseiten/{lesson}/bearbeiten', [LessonContentController::class, 'edit'])->name('lessons.edit');
    Route::put('lernseiten/{lesson}/inhalt', [LessonContentController::class, 'update'])->name('lessons.update');
});

Route::get('lernseiten/{lesson}/grafik', [LessonController::class, 'hero'])
    ->middleware('signed')
    ->name('lessons.hero');

// Für Kinder: nicht erratbarer Link pro Kind, nur freigegebene Seiten, kein Login
Route::middleware(['noindex', 'throttle:60,1'])->group(function () {
    Route::get('k/{token}', [SharedLessonController::class, 'index'])->name('shared.index');
    Route::get('k/{token}/{lesson}', [SharedLessonController::class, 'show'])->name('shared.show');
});

// Antworten für den Lernstand: eigenes, höheres Limit, weil ein Sortierspiel viele Antworten schickt
Route::post('k/{token}/{lesson}/antwort', [SharedLessonController::class, 'answer'])
    ->middleware(['noindex', 'throttle:answers'])
    ->name('shared.answer');

if (app()->isLocal()) {
    Route::get('vorschau/lernseiten/{lesson}', [LessonController::class, 'preview'])->name('lessons.preview');
}

require __DIR__.'/settings.php';
