# ADR 0001: Independent applications and a small development foundation

- Status: Accepted for Phase 1.0
- Date: 2026-09-30

## Context

CoreERP is a senior-level portfolio project whose long-term target is a multi-tenant ERP. The first milestone must prove reproducible development and quality checks without choosing domain behavior prematurely.

## Decision

1. Keep a Laravel REST API and Vue SPA in separate directories with independent manifests and lockfiles. No Laravel-hosted Vite assets, Inertia coupling, or duplicate frontend toolchain.
2. Use PHP 8.5, Laravel 13, PostgreSQL 18, Redis 8, and Node 24. Commit Composer/npm locks; container tags constrain runtime majors while allowing upstream patch updates. The Redis PHP extension is pinned explicitly. Runtime image digests can be pinned with an update policy when deployment work begins.
3. Use four Compose services, loopback-only application ports, an internal service network, persistent data volumes, and health-based startup ordering. Use non-root development application processes and host UID/GID mapping. Keep dependency installation and migrations explicit; container restart must not silently modify the schema or regenerate keys.
4. Separate liveness from readiness. Return minimal JSON through API Resources and use a small readiness service for dependency checks. Unit-like HTTP tests mock dependencies; a read-only integration test uses real services.
5. Install Sanctum, but disable its automatic routes and defer session authentication, CSRF integration, token schema, and auth flows to Phase 1.1. Retain only Laravel's standard identity scaffold and an empty seeder. No sample account is created.
6. Configure Redis cache, file sessions, and synchronous queues. Defer queue workers, real-time infrastructure, and Mailpit until actual milestone requirements need them.
7. Run CI in the same Compose runtime as local development. Enforce Pest, Pint, Larastan level 8, ESLint, Prettier, TypeScript checking, Vitest, and the production frontend build.

## Consequences

The applications can evolve independently while sharing review and CI. Developers need Docker rather than matching host runtimes. Initial image builds take longer because PHP extensions are compiled; subsequent builds are cached. Source and dependencies are bind-mounted for editor tooling, so host UID/GID configuration matters on Linux.

The PHP built-in server and Vite are development servers. Production serving, TLS, image hardening, and release orchestration require a separate decision. Minor/patch image updates are intentionally possible; package resolution is locked. Tenancy, RBAC, and business modules remain explicitly absent.

## References

- [Laravel Sanctum documentation](https://laravel.com/docs/13.x/sanctum)
- [Tailwind CSS Vite integration](https://tailwindcss.com/docs/installation/using-vite)
- [Official PostgreSQL image and PostgreSQL 18 volume layout](https://hub.docker.com/_/postgres)
