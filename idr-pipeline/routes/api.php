<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\SearchController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// IDR Document Upload & Status Polling
Route::post('/documents/upload', [DocumentController::class, 'upload']);
Route::get('/documents/{artifactId}/status', [DocumentController::class, 'status']);

Route::get('/search', [SearchController::class, 'search']);