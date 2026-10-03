<?php

use App\Modules\Audit\Application\Exceptions\AuditQueryInvalid;
use App\Modules\Notification\Application\Exceptions\NotificationNotFound;
use App\Modules\Notification\Application\Exceptions\NotificationQueryInvalid;
use App\Modules\Notification\Application\Exceptions\NotificationStorageFailed;
use App\Modules\Organization\Application\Authorization\AccessDenied;
use App\Modules\Organization\Application\Exceptions\RoleNameConflict;
use App\Modules\Organization\Domain\Invitations\InvitationRejected;
use App\Modules\Organization\Domain\Memberships\CrossOrganizationRoleAssignment;
use App\Modules\Organization\Domain\Memberships\OwnerMembershipProtected;
use App\Modules\Organization\Infrastructure\Authorization\AccessResponse;
use App\Modules\Organization\Presentation\Http\Exceptions\OrganizationFailureMapper;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->redirectGuestsTo(fn (Request $request) => $request->expectsJson() ? null : config('app.frontend_url').'/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['token', 'password', 'password_confirmation', 'current_password']);
        $exceptions->dontReport([InvitationRejected::class, NotificationNotFound::class]);
        $exceptions->map(AuditQueryInvalid::class, fn (AuditQueryInvalid $failure) => ValidationException::withMessages($failure->errors));
        $exceptions->map(NotificationQueryInvalid::class, fn (NotificationQueryInvalid $failure) => ValidationException::withMessages($failure->errors));
        $exceptions->render(fn (NotificationNotFound $failure) => response()->json(['message' => $failure->getMessage()], 404));
        $exceptions->render(fn (NotificationStorageFailed $failure) => response()->json(['message' => $failure->getMessage()], 503));
        $exceptions->render(OrganizationFailureMapper::invitationRejected(...));
        $exceptions->render(OrganizationFailureMapper::invitationDeliveryFailed(...));
        $exceptions->map(OwnerMembershipProtected::class, OrganizationFailureMapper::ownerMembershipProtected(...));
        $exceptions->map(AccessDenied::class, AccessResponse::exception(...));
        $exceptions->map(RoleNameConflict::class, OrganizationFailureMapper::roleNameConflict(...));
        $exceptions->map(CrossOrganizationRoleAssignment::class, OrganizationFailureMapper::crossOrganizationRoleAssignment(...));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
