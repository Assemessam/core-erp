<?php

use App\Modules\Organization\Application\Authorization\AccessDenied;
use App\Modules\Organization\Application\Exceptions\RoleNameConflict;
use App\Modules\Organization\Domain\Memberships\CrossOrganizationRoleAssignment;
use App\Modules\Organization\Infrastructure\Authorization\AccessResponse;
use App\Modules\Organization\Presentation\Http\Exceptions\OrganizationFailureMapper;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        $exceptions->map(AccessDenied::class, AccessResponse::exception(...));
        $exceptions->map(RoleNameConflict::class, OrganizationFailureMapper::roleNameConflict(...));
        $exceptions->map(CrossOrganizationRoleAssignment::class, OrganizationFailureMapper::crossOrganizationRoleAssignment(...));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
