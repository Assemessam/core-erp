<?php

use App\Modules\Audit\Application\Contracts\AuditHistoryAccess;
use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Notification\Application\Contracts\NotificationOrganizationAccess;
use App\Modules\Notification\Application\Contracts\NotificationPublisher;
use App\Modules\Notification\Application\Data\NotificationDraft;
use App\Modules\Notification\Application\Data\NotificationMembershipContext;
use App\Modules\Organization\Application\Commands\RenameOrganization;
use App\Modules\Organization\Application\Queries\ListOrganizations;
use App\Modules\Organization\Infrastructure\Audit\OrganizationAuditHistoryAccess;
use App\Modules\Organization\Infrastructure\Authorization\OrganizationPolicy;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationMembership;
use App\Modules\Organization\Infrastructure\Notification\OrganizationNotificationAccess;
use App\Modules\Organization\Infrastructure\Providers\OrganizationServiceProvider;
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

arch('Audit Infrastructure does not depend on HTTP Presentation')
    ->expect('App\\Modules\\Audit\\Infrastructure')->not->toUse('App\\Modules\\Audit\\Presentation');

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
    ->not->toUse('App\\Modules\\Audit')
    ->ignoring([OrganizationAuditHistoryAccess::class, OrganizationServiceProvider::class]);

arch('only the Organization authorization adapter and provider consume the Audit access port')
    ->expect([OrganizationAuditHistoryAccess::class, OrganizationServiceProvider::class])
    ->not->toUse('App\\Modules\\Audit')->ignoring(AuditHistoryAccess::class);

arch('Organization does not consume the internal Audit reader or query types')
    ->expect('App\\Modules\\Organization')
    ->not->toUse(['App\\Modules\\Audit\\Application\\Contracts\\AuditEventReader', 'App\\Modules\\Audit\\Application\\Queries']);

arch('Audit Presentation delegates persistence and business authorization')
    ->expect('App\\Modules\\Audit\\Presentation')
    ->not->toUse(['App\\Modules\\Audit\\Infrastructure', DB::class, DatabaseManager::class, ConnectionInterface::class,
        'Illuminate\\Database', Gate::class, Auth::class]);

arch('Notification never consumes Organization Identity or Audit internals')
    ->expect('App\\Modules\\Notification')
    ->not->toUse(['App\\Modules\\Organization', 'App\\Modules\\Identity', 'App\\Modules\\Audit']);

arch('Notification Application is framework independent')
    ->expect('App\\Modules\\Notification\\Application')
    ->not->toUse(['Illuminate', 'Laravel', 'Symfony', 'App\\Modules\\Notification\\Infrastructure',
        'App\\Modules\\Notification\\Presentation', 'app', 'auth', 'request', 'response', 'session',
        'resolve', 'config', 'event', 'dispatch']);

arch('Audit and Identity have no Notification dependency')
    ->expect(['App\\Modules\\Audit', 'App\\Modules\\Identity'])->not->toUse('App\\Modules\\Notification');

arch('Organization Domain and Application publish no notifications through checkpoint C')
    ->expect(['App\\Modules\\Organization\\Domain', 'App\\Modules\\Organization\\Application'])
    ->not->toUse('App\\Modules\\Notification');

arch('only the approved Organization adapter and provider consume Notification')
    ->expect('App\\Modules\\Organization')->not->toUse('App\\Modules\\Notification')
    ->ignoring([OrganizationNotificationAccess::class, OrganizationServiceProvider::class]);

arch('Organization composition consumes only the Notification access contract and context')
    ->expect([OrganizationNotificationAccess::class, OrganizationServiceProvider::class])
    ->not->toUse('App\\Modules\\Notification')
    ->ignoring([NotificationOrganizationAccess::class, NotificationMembershipContext::class]);

arch('Notification Infrastructure does not invoke business commands or HTTP Presentation')
    ->expect('App\\Modules\\Notification\\Infrastructure')
    ->not->toUse(['App\\Modules\\Notification\\Application\\Commands', 'App\\Modules\\Notification\\Presentation']);

arch('Notification Presentation delegates persistence and does not publish')
    ->expect('App\\Modules\\Notification\\Presentation')
    ->not->toUse(['App\\Modules\\Notification\\Infrastructure', 'Illuminate\\Database', DB::class,
        DatabaseManager::class, ConnectionInterface::class, PDO::class, NotificationPublisher::class, NotificationDraft::class]);

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

it('keeps checkpoint E Audit without a domain layer or Eloquent mutation model', function () use ($appDirectory) {
    foreach (['Domain', 'Infrastructure/Eloquent'] as $directory) {
        expect(is_dir($appDirectory.'/Modules/Audit/'.$directory))->toBeFalse();
    }
    expect(is_dir($appDirectory.'/Modules/Audit/Presentation/Http'))->toBeTrue();
    expect(is_dir($appDirectory.'/Modules/Audit/Application/Queries'))->toBeTrue();
});

it('keeps checkpoint C Notification without Domain Eloquent or delivery infrastructure', function () use ($appDirectory) {
    foreach (['Domain', 'Infrastructure/Eloquent', 'Infrastructure/Mail', 'Infrastructure/Jobs'] as $directory) {
        expect(is_dir($appDirectory.'/Modules/Notification/'.$directory))->toBeFalse();
    }
    expect(is_dir($appDirectory.'/Modules/Notification/Application'))->toBeTrue();
    expect(is_dir($appDirectory.'/Modules/Notification/Infrastructure'))->toBeTrue();
    expect(is_dir($appDirectory.'/Modules/Notification/Presentation/Http'))->toBeTrue();
    expect(is_dir($appDirectory.'/Modules/Notification/Application/Queries'))->toBeTrue();
    expect(is_dir($appDirectory.'/Modules/Notification/Application/Commands'))->toBeTrue();
});

it('keeps Notification reader read only and read-state store limited to read_at updates', function () use ($appDirectory) {
    $parser = (new ParserFactory)->createForHostVersion();
    $finder = new NodeFinder;
    foreach (['DatabaseNotificationReader', 'DatabaseNotificationReadStore'] as $type) {
        $nodes = $parser->parse(file_get_contents($appDirectory.'/Modules/Notification/Infrastructure/Persistence/'.$type.'.php')) ?? [];
        $forbidden = ['insert', 'delete', 'truncate', 'transaction', 'begintransaction', 'commit', 'rollback', 'aftercommit', 'dispatch', 'offset', 'join'];
        if ($type === 'DatabaseNotificationReader') {
            $forbidden[] = 'update';
        }
        $calls = $finder->find($nodes, fn (Node $node): bool => ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\NullsafeMethodCall)
            && $node->name instanceof Node\Identifier && in_array(strtolower($node->name->toString()), $forbidden, true));
        expect($calls)->toBeEmpty();
        $updates = $finder->find($nodes, fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier && $node->name->toString() === 'update');
        foreach ($updates as $update) {
            $values = $update->args[0]->value;
            expect($values)->toBeInstanceOf(Node\Expr\Array_::class);
            expect($values->items)->toHaveCount(1);
            expect($values->items[0]->key)->toBeInstanceOf(Node\Scalar\String_::class);
            expect($values->items[0]->key->value)->toBe('read_at');
        }
    }
});

it('keeps notification transaction ownership and default connection explicit', function () use ($appDirectory) {
    $parser = (new ParserFactory)->createForHostVersion();
    $finder = new NodeFinder;
    $nodes = $parser->parse(file_get_contents($appDirectory.'/Modules/Notification/Infrastructure/Persistence/DatabaseNotificationPublisher.php')) ?? [];
    $violations = $finder->find($nodes, fn (Node $node): bool => ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
        && $node->name instanceof Node\Identifier
        && in_array(strtolower($node->name->toString()), ['transaction', 'begintransaction', 'commit', 'rollback', 'aftercommit', 'dispatch', 'notify'], true));
    expect($violations)->toBeEmpty();
    $connections = $finder->find($nodes, fn (Node $node): bool => $node instanceof Node\Expr\StaticCall
        && $node->name instanceof Node\Identifier && $node->name->toString() === 'connection');
    expect($connections)->toHaveCount(1);
    expect($connections[0]->args)->toBeEmpty();
});

it('keeps the Audit history reader read only and scoped in Infrastructure', function () use ($appDirectory) {
    $parser = (new ParserFactory)->createForHostVersion();
    $finder = new NodeFinder;
    $nodes = $parser->parse(file_get_contents($appDirectory.'/Modules/Audit/Infrastructure/Persistence/DatabaseAuditEventReader.php')) ?? [];
    $writes = $finder->find($nodes, fn (Node $node): bool => ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
        && $node->name instanceof Node\Identifier
        && in_array(strtolower($node->name->toString()), ['insert', 'update', 'delete', 'truncate', 'transaction', 'begintransaction', 'commit', 'rollback', 'offset', 'join'], true));
    expect($writes)->toBeEmpty();
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
    if (basename($directory) !== 'Notification') {
        arch(basename($directory).' does not consume private Notification implementation')
            ->expect($namespace)->not->toUse([
                'App\\Modules\\Notification\\Infrastructure', 'App\\Modules\\Notification\\Presentation',
                'App\\Modules\\Notification\\Application\\Validation', 'App\\Modules\\Notification\\Application\\Content',
                'App\\Modules\\Notification\\Application\\Exceptions',
            ]);
    }
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
