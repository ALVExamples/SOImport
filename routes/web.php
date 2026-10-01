<?php

use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ImportController::class, 'page'])->name('imports.page');

Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
Route::post('/imports/chunks', [ImportController::class, 'chunk'])->name('imports.chunk');
Route::get('/imports/{import}', [ImportController::class, 'show'])->name('imports.show');
