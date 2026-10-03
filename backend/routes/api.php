<?php

use App\Http\Controllers\HealthController;
use App\Modules\Audit\Presentation\Http\Controllers\AuditEventController;
use App\Modules\Identity\Presentation\Http\Controllers\CurrentUserController;
use App\Modules\Notification\Presentation\Http\Controllers\NotificationController;
use App\Modules\Organization\Presentation\Http\Controllers\OrganizationController;
use App\Modules\Organization\Presentation\Http\Controllers\OrganizationInvitationController;
use App\Modules\Organization\Presentation\Http\Controllers\OrganizationMemberController;
use App\Modules\Organization\Presentation\Http\Controllers\OrganizationRoleController;
use App\Modules\Organization\Presentation\Http\Controllers\OrganizationUsersAccessController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'live']);
Route::get('/ready', [HealthController::class, 'ready']);
Route::get('/me', CurrentUserController::class)->middleware('auth:sanctum');
Route::middleware(['auth:sanctum', 'verified'])->group(function (): void {
    Route::get('/organizations/{organization}/audit-events', AuditEventController::class)->whereUlid('organization');
    Route::prefix('/organizations/{organization}/notifications')->whereUlid('organization')->group(function (): void {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/read-all', [NotificationController::class, 'readAll']);
        Route::post('/{notification}/read', [NotificationController::class, 'read'])->whereUlid('notification');
    });
    Route::post('/invitations/{invitation}/accept', [OrganizationInvitationController::class, 'accept'])->middleware('throttle:30,1');
    Route::scopeBindings()->group(function (): void {
        Route::get('/organizations/{organization}/users-access', OrganizationUsersAccessController::class);
        Route::get('/organizations/{organization}/members', [OrganizationMemberController::class, 'index']);
        Route::put('/organizations/{organization}/members/{membership}/roles', [OrganizationMemberController::class, 'roles']);
        Route::post('/organizations/{organization}/members/{membership}/suspend', [OrganizationMemberController::class, 'suspend']);
        Route::post('/organizations/{organization}/members/{membership}/activate', [OrganizationMemberController::class, 'activate']);
        Route::delete('/organizations/{organization}/members/{membership}', [OrganizationMemberController::class, 'destroy']);
        Route::get('/organizations/{organization}/invitations', [OrganizationInvitationController::class, 'index']);
        Route::post('/organizations/{organization}/invitations', [OrganizationInvitationController::class, 'store'])->middleware('throttle:30,1');
        Route::delete('/organizations/{organization}/invitations/{invitation}', [OrganizationInvitationController::class, 'destroy']);
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
