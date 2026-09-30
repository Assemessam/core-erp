<?php

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

// Architecture tests intentionally do not boot Laravel or use a database.
$appDirectory = dirname(__DIR__, 2).'/app';
$moduleDirectories = glob($appDirectory.'/Modules/*', GLOB_ONLYDIR) ?: [];
$controllerNamespaces = ['App\\Http\\Controllers'];
$controllerDirectories = [$appDirectory.'/Http/Controllers'];
$domainNamespaces = [];
$applicationNamespaces = [];
$outerNamespaces = ['App\\Http', 'App\\Models', 'App\\Actions', 'App\\Policies', 'App\\Providers', 'App\\Services'];
$presentationNamespaces = ['App\\Http'];

foreach ($moduleDirectories as $directory) {
    $namespace = 'App\\Modules\\'.basename($directory);
    $outerNamespaces = [...$outerNamespaces, $namespace.'\\Application', $namespace.'\\Infrastructure', $namespace.'\\Presentation'];
    $presentationNamespaces[] = $namespace.'\\Presentation';

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
    ->expect('App\\Models')
    ->not->toUse('App\\Http');

arch('business actions models policies and enums do not read ambient session or request context')
    ->expect(['App\\Actions', 'App\\Models', 'App\\Policies', 'App\\Enums'])
    ->not->toUse([Session::class, 'Illuminate\\Contracts\\Session', 'Illuminate\\Session', 'session', 'request']);

it('keeps Domain independent of framework and outer layers when introduced', function () use ($domainNamespaces, $outerNamespaces) {
    expect($domainNamespaces)->not->toUse([
        'Illuminate', 'Laravel', 'Symfony', ...$outerNamespaces,
        'app', 'auth', 'request', 'response', 'session', 'resolve', 'config', 'event', 'dispatch', 'abort',
    ]);
})->skip($domainNamespaces === [], 'No module Domain layer exists yet; activates automatically when introduced.');

it('keeps Application independent of HTTP delivery when introduced', function () use ($applicationNamespaces, $presentationNamespaces) {
    expect($applicationNamespaces)->not->toUse([
        ...$presentationNamespaces,
        'Illuminate\\Http', 'Illuminate\\Foundation\\Http', 'Illuminate\\Routing',
        'Symfony\\Component\\HttpFoundation', 'Symfony\\Component\\HttpKernel',
        'auth', 'request', 'response', 'session', 'abort', 'abort_if', 'abort_unless',
    ]);
})->skip($applicationNamespaces === [], 'No module Application layer exists yet; activates automatically when introduced.');

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
