<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

use App\Http\Controllers\TransactionUploadController;

Route::post('/upload-csv', [TransactionUploadController::class, 'upload']);
Route::get('/transaction-import-stats', [TransactionImportLogController::class, 'stats']);
