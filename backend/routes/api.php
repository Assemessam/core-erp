<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationRoleController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'live']);
Route::get('/ready', [HealthController::class, 'ready']);
Route::get('/me', fn (Request $request) => new UserResource($request->user()))->middleware('auth:sanctum');
Route::middleware(['auth:sanctum', 'verified'])->group(function (): void {
    Route::scopeBindings()->group(function (): void {
        Route::get('/organizations/{organization}/roles', [OrganizationRoleController::class, 'index']);
        Route::post('/organizations/{organization}/roles', [OrganizationRoleController::class, 'store']);
        Route::patch('/organizations/{organization}/roles/{role}', [OrganizationRoleController::class, 'update']);
        Route::get('/organizations/{organization}/permissions', [OrganizationRoleController::class, 'permissions']);
    });
    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
    Route::patch('/organizations/{organization}', [OrganizationController::class, 'update']);
});
