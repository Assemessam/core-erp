<?php

use App\Http\Controllers\HealthController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'live']);
Route::get('/ready', [HealthController::class, 'ready']);
Route::get('/me', fn (Request $request) => new UserResource($request->user()))->middleware('auth:sanctum');
