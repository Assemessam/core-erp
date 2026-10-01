<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Commands\RenameOrganization;
use App\Modules\Organization\Application\Queries\ListOrganizations;
use App\Modules\Organization\Infrastructure\Authorization\OrganizationPolicy;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationMembership;
use App\Modules\Organization\Presentation\Http\Controllers\OrganizationController;
use App\Modules\Organization\Presentation\Http\Controllers\OrganizationInvitationController;
use App\Modules\Organization\Presentation\Http\Controllers\OrganizationMemberController;
use App\Modules\Organization\Presentation\Http\Controllers\OrganizationRoleController;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

// Architecture tests intentionally do not boot Laravel or use a database.
arch('Identity does not depend on Organization')
    ->expect('App\\Modules\\Identity')->not->toUse('App\\Modules\\Organization');

arch('Organization does not consume Identity internals other than the User persistence type')
    ->expect('App\\Modules\\Organization')->not->toUse('App\\Modules\\Identity')
    ->ignoring(User::class);

arch('only existing organization relationships and actor adapters consume the Identity User')
    ->expect('App\\Modules\\Organization')->not->toUse(User::class)
    ->ignoring([
        Organization::class, OrganizationMembership::class, OrganizationPolicy::class,
        OrganizationController::class, OrganizationRoleController::class,
        OrganizationMemberController::class,
        OrganizationInvitationController::class,
    ]);

arch('Organization Application does not depend on Identity')
    ->expect('App\\Modules\\Organization\\Application')->not->toUse('App\\Modules\\Identity');

arch('Audit never consumes Organization or Identity internals')
    ->expect('App\\Modules\\Audit')
    ->not->toUse(['App\\Modules\\Organization', 'App\\Modules\\Identity']);

arch('Audit Application is framework independent')
    ->expect('App\\Modules\\Audit\\Application')
    ->not->toUse(['Illuminate', 'Laravel', 'Symfony', 'App\\Modules\\Audit\\Infrastructure', 'App\\Modules\\Audit\\Presentation',
        'app', 'auth', 'request', 'response', 'session', 'resolve', 'config', 'event', 'dispatch']);

arch('Organization Domain does not depend on Audit')
    ->expect('App\\Modules\\Organization\\Domain')->not->toUse('App\\Modules\\Audit');

arch('Organization consumes only the public Audit producer namespaces')
    ->expect('App\\Modules\\Organization')->not->toUse('App\\Modules\\Audit')
    ->ignoring([
        'App\\Modules\\Audit\\Application\\Contracts',
        'App\\Modules\\Audit\\Application\\Data',
        'App\\Modules\\Audit\\Application\\Vocabulary',
    ]);

arch('Organization delivery and infrastructure do not orchestrate audit facts')
    ->expect(['App\\Modules\\Organization\\Presentation', 'App\\Modules\\Organization\\Infrastructure'])
    ->not->toUse('App\\Modules\\Audit');

$appDirectory = dirname(__DIR__, 2).'/app';
$moduleDirectories = glob($appDirectory.'/Modules/*', GLOB_ONLYDIR) ?: [];
$controllerNamespaces = ['App\\Http\\Controllers'];
$controllerDirectories = [$appDirectory.'/Http/Controllers'];
$domainNamespaces = [];
$applicationNamespaces = [];
$outerNamespaces = ['App\\Http', 'App\\Models', 'App\\Actions', 'App\\Policies', 'App\\Providers', 'App\\Services'];
$presentationNamespaces = ['App\\Http'];
$modelNamespaces = ['App\\Models'];
$businessNamespaces = ['App\\Actions', 'App\\Models'];

it('has no obsolete global business directories', function () use ($appDirectory) {
    foreach (['Actions', 'Models', 'Enums', 'Policies', 'Queries', 'Http/Requests', 'Http/Responses'] as $directory) {
        expect(is_dir($appDirectory.'/'.$directory))->toBeFalse($directory.' belongs in an owning module.');
    }
});

it('keeps checkpoint C audit layers limited to contracts and persistence', function () use ($appDirectory) {
    foreach (['Domain', 'Presentation', 'Infrastructure/Eloquent', 'Application/Queries'] as $directory) {
        expect(is_dir($appDirectory.'/Modules/Audit/'.$directory))->toBeFalse();
    }
});

it('keeps audit transaction ownership in the caller', function () use ($appDirectory) {
    $parser = (new ParserFactory)->createForHostVersion();
    $finder = new NodeFinder;
    $code = file_get_contents($appDirectory.'/Modules/Audit/Infrastructure/Persistence/DatabaseAuditRecorder.php');
    $nodes = $parser->parse($code) ?? [];
    $violations = $finder->find($nodes, function (Node $node): bool {
        return ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
            && $node->name instanceof Node\Identifier
            && in_array(strtolower($node->name->toString()), ['transaction', 'begintransaction', 'commit', 'rollback', 'aftercommit', 'dispatch'], true);
    });
    expect($violations)->toBeEmpty();
    $connections = $finder->find($nodes, fn (Node $node): bool => $node instanceof Node\Expr\StaticCall
        && $node->name instanceof Node\Identifier && $node->name->toString() === 'connection');
    expect($connections)->toHaveCount(1);
    expect($connections[0]->args)->toBeEmpty();
});

foreach ($moduleDirectories as $directory) {
    $namespace = 'App\\Modules\\'.basename($directory);
    if (basename($directory) !== 'Audit') {
        arch(basename($directory).' does not consume private Audit implementation')
            ->expect($namespace)->not->toUse([
                'App\\Modules\\Audit\\Infrastructure', 'App\\Modules\\Audit\\Presentation',
                'App\\Modules\\Audit\\Application\\Validation', 'App\\Modules\\Audit\\Application\\Exceptions',
            ]);
    }
    $outerNamespaces = [...$outerNamespaces, $namespace.'\\Application', $namespace.'\\Infrastructure', $namespace.'\\Presentation'];
    $presentationNamespaces[] = $namespace.'\\Presentation';
    $businessNamespaces = [...$businessNamespaces, $namespace.'\\Domain', $namespace.'\\Application', $namespace.'\\Infrastructure'];
    if (is_dir($directory.'/Infrastructure/Eloquent/Models')) {
        $modelNamespaces[] = $namespace.'\\Infrastructure\\Eloquent\\Models';
    }

    if (is_dir($directory.'/Domain')) {
        $domainNamespaces[] = $namespace.'\\Domain';
    }
    if (is_dir($directory.'/Application')) {
        $applicationNamespaces[] = $namespace.'\\Application';
    }
    if (is_dir($directory.'/Presentation/Http/Controllers')) {
        $controllerNamespaces[] = $namespace.'\\Presentation\\Http\\Controllers';
        $controllerDirectories[] = $directory.'/Presentation/Http/Controllers';
    }
}

arch('controllers do not depend directly on database facades managers or connections')
    ->expect($controllerNamespaces)
    ->not->toUse([DB::class, DatabaseManager::class, ConnectionInterface::class, PDO::class]);

arch('persistence models do not depend on HTTP delivery classes')
    ->expect($modelNamespaces)
    ->not->toUse($presentationNamespaces);

arch('business actions models policies and enums do not read ambient session or request context')
    ->expect($businessNamespaces)
    ->not->toUse([Session::class, 'Illuminate\\Contracts\\Session', 'Illuminate\\Session', 'session', 'request']);

arch('extracted organization operations are independent of HTTP and ambient actor context')
    ->expect([ListOrganizations::class, RenameOrganization::class])
    ->not->toUse([
        'App\\Http', 'Illuminate\\Http', 'Illuminate\\Foundation\\Http', 'Illuminate\\Routing',
        'Symfony\\Component\\HttpFoundation', 'Symfony\\Component\\HttpKernel\\Exception',
        'Illuminate\\Validation\\ValidationException', 'Illuminate\\Contracts\\Auth',
        Auth::class, Gate::class, Session::class,
        'auth', 'request', 'response', 'session', 'abort', 'abort_if', 'abort_unless',
    ]);

it('keeps Domain independent of framework and outer layers', function () use ($domainNamespaces, $outerNamespaces) {
    expect($domainNamespaces)->not->toBeEmpty();
    expect($domainNamespaces)->not->toUse([
        'Illuminate', 'Laravel', 'Symfony', ...$outerNamespaces,
        'app', 'auth', 'request', 'response', 'session', 'resolve', 'config', 'event', 'dispatch', 'abort',
    ]);
});

it('keeps Application independent of HTTP delivery', function () use ($applicationNamespaces, $presentationNamespaces) {
    expect($applicationNamespaces)->not->toBeEmpty();
    expect($applicationNamespaces)->not->toUse([
        ...$presentationNamespaces,
        'Illuminate\\Http', 'Illuminate\\Foundation\\Http', 'Illuminate\\Routing',
        'Symfony\\Component\\HttpFoundation', 'Symfony\\Component\\HttpKernel\\Exception',
        Auth::class, Gate::class, Session::class, 'Illuminate\\Contracts\\Auth\\Access',
        'App\\Modules\\Organization\\Infrastructure\\Authorization',
        'auth', 'request', 'response', 'session', 'abort', 'abort_if', 'abort_unless',
    ]);
    expect($applicationNamespaces)->not->toUse('Illuminate\\Validation\\ValidationException');
    expect($applicationNamespaces)->not->toUse('App\\Modules\\Identity');
});

it('keeps explicit transaction control out of controllers', function () use ($controllerDirectories) {
    // Inspect PHP syntax, not text patterns; this also catches Model::getConnection()->transaction().
    $parser = (new ParserFactory)->createForHostVersion();
    $finder = new NodeFinder;

    foreach (Finder::create()->files()->in($controllerDirectories)->name('*.php') as $file) {
        $violations = $finder->find($parser->parse($file->getContents()) ?? [], function (Node $node): bool {
            return ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\NullsafeMethodCall)
                && $node->name instanceof Node\Identifier
                && in_array(strtolower($node->name->toString()), ['transaction', 'begintransaction', 'commit', 'rollback'], true);
        });

        expect($violations)->toBeEmpty($file->getRelativePathname().' must delegate transaction control to an application use case.');
    }
});

it('keeps explicit persistence mutation calls out of controllers', function () use ($controllerDirectories) {
    // A syntax guard for common Eloquent/query-builder writes, not data-flow analysis.
    // Indirect/dynamic calls still require review and behavior tests.
    $parser = (new ParserFactory)->createForHostVersion();
    $finder = new NodeFinder;

    foreach (Finder::create()->files()->in($controllerDirectories)->name('*.php') as $file) {
        $violations = $finder->find($parser->parse($file->getContents()) ?? [], function (Node $node): bool {
            return ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\NullsafeMethodCall)
                && $node->name instanceof Node\Identifier
                && in_array(strtolower($node->name->toString()), [
                    'save', 'savequietly', 'update', 'updatequietly', 'create', 'delete', 'forcedelete', 'destroy',
                    'insert', 'upsert', 'updateorcreate', 'firstorcreate', 'increment', 'decrement',
                    'sync', 'syncwithoutdetaching', 'attach', 'detach', 'truncate',
                ], true);
        });

        expect($violations)->toBeEmpty($file->getRelativePathname().' must delegate persistence mutations to an application operation.');
    }
});

it('does not introduce PHP global state in application code', function () use ($appDirectory) {
    // This blocks global/$GLOBALS, not every possible ambient tenant mechanism. See ADR 0005.
    $parser = (new ParserFactory)->createForHostVersion();
    $finder = new NodeFinder;

    foreach (Finder::create()->files()->in($appDirectory)->name('*.php') as $file) {
        $violations = $finder->find($parser->parse($file->getContents()) ?? [], function (Node $node): bool {
            return $node instanceof Node\Stmt\Global_
                || ($node instanceof Node\Expr\Variable && $node->name === 'GLOBALS');
        });

        expect($violations)->toBeEmpty($file->getRelativePathname().' must pass actor and organization context explicitly.');
    }
});
