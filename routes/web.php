<?php

use App\Http\Controllers\ItemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReviewCommentController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', [ItemController::class, 'index'])
    ->name('home');
Route::get('/items', [ItemController::class, 'index'])
    ->name('items.index');
Route::get('/items/{item}', [ItemController::class, 'show'])
    ->name('items.show');

Route::middleware('auth')->group(function () {
    Route::middleware('verified')->group(function () {

        Route::post('/items/{item}/reviews', [ReviewController::class, 'store'])
            ->name('reviews.store');

        Route::post('/reviews/{review}/comments', [ReviewCommentController::class, 'store'])
            ->name('reviews.comments.store');
    });

    Route::get('/my-reviews', [ReviewController::class, 'mine'])
        ->name('reviews.mine');

    Route::delete('/reviews/{review}', [ReviewController::class, 'destroy'])
        ->name('reviews.destroy');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
