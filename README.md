# CoreERP

CoreERP is a portfolio-grade ERP under active development, built to demonstrate disciplined software engineering: clear boundaries, automated verification, security, and maintainable architecture.

**Current checkpoint: Phase 1.3.5E — Domain Invariants and Application Error Cleanup.** Organization now lives under `App\Modules\Organization`, with Domain, Application, Infrastructure, and Presentation layers. Phase 1.3 organization-scoped RBAC is complete. The repository contains an operational application shell, first-party SPA authentication, an organization tenant boundary, and infrastructure probes. [ADR 0005](docs/decisions/0005-ddd-modular-monolith-architecture.md) defines the approved modular-monolith direction; Policies and authorized Organization writes now share a persisted access evaluator. Membership-role tenant compatibility is now a pure Domain rule, with Application failures translated at the HTTP boundary. Identity remains in its existing namespaces; further RBAC extraction is deferred. Member administration and ERP business modules are not implemented.

## Architecture

```text
Vue SPA → Laravel REST API (/api/v1) → PostgreSQL
        → Sanctum / Fortify         → Redis sessions/cache
                                     → Mailpit (local email)
```

The backend and frontend are separate applications in one repository, with independent dependency manifests, lockfiles, and quality tooling. The development Vite server proxies `/api`, `/sanctum`, and Fortify mutation routes to Laravel. Organizations use shared-database, shared-schema tenancy. Future ERP tables must add explicit organization keys and authorization.

See the [system overview](docs/architecture/system-overview.md), [foundation decision](docs/decisions/0001-foundation.md), [authentication decision](docs/decisions/0002-spa-authentication.md), [tenancy decision](docs/decisions/0003-multi-tenancy-and-organizations.md), [RBAC decision](docs/decisions/0004-organization-scoped-rbac.md), and [Phase 1 roadmap](docs/phases/phase-01-core-platform.md).

## Technology stack

| Area | Foundation |
| --- | --- |
| API and authentication | Laravel 13, PHP 8.5, Sanctum SPA sessions, Fortify |
| Data and local mail | PostgreSQL 18, Redis 8, Mailpit |
| Backend quality | Pest 4, Larastan/PHPStan level 8, Laravel Pint |
| SPA | Vue 3, TypeScript, Composition API, `<script setup>`, Vite |
| Client libraries | Vue Router, Pinia, Axios, Tailwind CSS 4 |
| Frontend quality | Vitest, Vue Test Utils, Playwright, ESLint, Prettier, vue-tsc |
| Runtime | Node 24 LTS, Docker Compose |

Pinia stores public current-user and organization representations. It never stores tokens or session IDs; the selected organization comes from the route, and readiness stays local to its view.

## Repository structure

```text
backend/                  Laravel API and backend tests
frontend/                 Vue SPA and frontend tests
docs/
  architecture/           System overview
  decisions/              Architectural decision records
  phases/                 Milestone scope and roadmap
  domain/                 Reserved for future domain documentation
docker/                   Development Dockerfiles
.github/workflows/        CI quality pipeline
docker-compose.yml        Local application and data services
AGENTS.md                 Coding-agent guidance
```

## Local development

Install Docker Engine/Desktop with Docker Compose v2 or later. Docker is the supported development path; host PHP, Composer, and Node are not required. Run these commands from the repository root.

First-time setup:

```sh
cp .env.example .env
cp backend/.env.example backend/.env
```

On Linux, set `LOCAL_UID` and `LOCAL_GID` in the root `.env` to the output of `id -u` and `id -g` if they differ from 1000. This keeps bind-mounted dependencies and generated files owned by your user. The root `.env` controls Compose; `backend/.env` controls Laravel. Neither file is tracked. Do not overwrite an existing environment file during subsequent starts.

```sh
docker compose config --quiet
docker compose build
docker compose run --rm --no-deps backend composer install --no-interaction --prefer-dist
docker compose run --rm --no-deps frontend npm ci
docker compose run --rm --no-deps backend php artisan key:generate --no-interaction
docker compose up -d --wait postgres redis
docker compose run --rm backend php artisan migrate --no-interaction
docker compose up -d --wait
```

The schema includes users, password-reset/session scaffolding, organizations, memberships, organization roles, and relational permission grants. There are no seeded users. Redis stores sessions, so the standard `sessions` table is unused. No Sanctum personal-access-token migration or token issuance is added.

- Frontend: <http://localhost:5174>
- API liveness: <http://localhost:8088/api/v1/health>
- API readiness: <http://localhost:8088/api/v1/ready>
- Mailpit inbox: <http://localhost:8026>

To try the application, open the frontend, create an account, then open Mailpit to follow its signed verification link. The link briefly opens the Laravel origin at port 8088 and returns to the SPA's organization onboarding screen. Create an organization, then enter its workspace at `/app/organizations/{organizationId}`. Open **Roles & Permissions** in the workspace to create or edit roles and their permissions. The explicit owner manages roles without needing an Owner role; assigning roles to members through the UI is deferred to Phase 1.4. Password-reset messages also appear in Mailpit. Mailpit SMTP stays inside Docker on port 1025; its web UI binds only to loopback. Its inbox is ephemeral local development data.

Subsequent starts use `docker compose up -d --wait` (add `--build` after Dockerfile changes). After lockfile changes, rerun the dependency installation commands. Bind mounts provide source hot reload; dependencies live in ignored `backend/vendor` and `frontend/node_modules` directories.

Ports 8088, 5174, and 8026 avoid common local conflicts and bind only to loopback. Override `BACKEND_PORT` / `FRONTEND_PORT` / `MAILPIT_PORT` in the root `.env` as needed; keep Laravel's `APP_URL`, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, and `CORS_ALLOWED_ORIGINS` aligned with the exposed origins. PostgreSQL and Redis have no published host ports and use persistent named volumes. PostgreSQL 18 mounts its data volume at `/var/lib/postgresql`.

```sh
docker compose ps
docker compose logs --tail=100 backend frontend
docker compose stop
docker compose down
```

`down` retains data volumes; `down -v` deletes this project's database and Redis data. Local credentials and development servers are intentionally for local use only. This Compose configuration is not a production deployment. Mail goes to Mailpit; no transactional email provider is configured.

## Backend commands

```sh
docker compose exec backend php artisan about
docker compose exec backend php artisan route:list --path=api
docker compose exec backend php artisan migrate:status
docker compose exec backend php artisan route:list
docker compose exec backend composer test
docker compose exec backend composer lint
docker compose exec backend composer analyse
docker compose exec backend composer quality
docker compose exec backend composer format
docker compose exec backend composer validate --strict
docker compose exec backend composer check-platform-reqs
```

`composer test` runs feature tests plus the real PostgreSQL/Redis readiness integration test, so the data services must be available. The integration test only reads: it does not migrate, truncate, or flush development data. For dependency-independent feature tests: `docker compose exec backend composer test -- --exclude-group=integration`.

`lint` checks Pint formatting without editing files; `format` applies formatting. Larastan runs at level 8 with no baseline or suppressed application errors.

## Frontend commands

```sh
docker compose exec frontend npm run lint
docker compose exec frontend npm run format:check
docker compose exec frontend npm run type-check
docker compose exec frontend npm test
docker compose exec frontend npm run test:watch
docker compose exec frontend npm run test:e2e
docker compose exec frontend npm run build
docker compose exec frontend npm run quality
docker compose exec frontend npm run format
```

The Playwright command runs on a host with Chrome installed. CI runs it in the pinned Playwright browser image. For the same browser environment locally, use `docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test` from the repository root after the stack is healthy.

To run the SPA on the host instead, use Node 24 (`nvm use` from the repository root), then `cd frontend`, `npm ci`, and `npm run dev`. Stop the Compose frontend if running this alternative. Host Vite defaults to port 5173 and proxies to `http://127.0.0.1:8088`; include that port in `SANCTUM_STATEFUL_DOMAINS` and `CORS_ALLOWED_ORIGINS` when using it. Override `API_PROXY_TARGET` if the API port changes. Vite preview serves build assets only, without the development API proxy; production routing is a later deployment concern.

## API and verification

```sh
curl --fail http://localhost:8088/api/v1/health
curl --fail http://localhost:8088/api/v1/ready
curl --fail http://localhost:5174/api/v1/ready
```

Both endpoints return `{"data":{"status":"ok"}}` when healthy. Liveness does not access external dependencies. Readiness executes `SELECT 1` and Redis `PING`; it returns HTTP 503 with `{"data":{"status":"unavailable"}}` on failure. It checks connectivity, not migration currency or future domain invariants. Unknown `/api/*` routes produce JSON errors even without an `Accept` header.

The [CI workflow](.github/workflows/quality.yml) builds the same containers, installs locked dependencies, applies the framework migration, starts all five services, checks API and Mailpit connectivity, runs both quality suites, and executes Playwright in a pinned browser image. GitHub-hosted execution occurs after you push; local results are recorded in [foundation validation](docs/phases/phase-01-foundation-validation.md) and [authentication validation](docs/phases/phase-01-authentication-validation.md).

## Authentication request flow

The SPA calls `/sanctum/csrf-cookie`, then Fortify's `/register`, `/login`, `/logout`, `/forgot-password`, `/reset-password`, or `/email/verification-notification` routes through Vite's same-origin proxy. Axios sends credentials and the XSRF header. Laravel authenticates with a server-side Redis session; the browser's session cookie is HttpOnly. On refresh, the SPA requests `GET /api/v1/me` before entering a guarded route. Guests get 401. Authenticated users receive only ID, name, email, and verification status. Unverified users can reach `/api/v1/me` and the verification page but cannot enter organization SPA routes. Organization APIs also enforce verification server-side.

The password-reset request responds the same way for registered and unregistered addresses. The reset link opens the SPA and a successful reset leaves the user signed out. Login is rate limited; verification resends are throttled. Validation errors use HTTP 422, expired or invalid CSRF produces 419, and rate limits produce 429. The SPA displays these states without automatic retry loops.

Development uses the same `localhost` host on both ports so cookies work through the proxy. Do not switch only one origin to `127.0.0.1`. For production subdomains, set `VITE_API_ORIGIN` at frontend build time, `APP_URL` and `FRONTEND_URL` to their HTTPS origins, narrow `CORS_ALLOWED_ORIGINS`, include the SPA host in `SANCTUM_STATEFUL_DOMAINS`, set `SESSION_DOMAIN` to the shared parent domain, and enable `SESSION_SECURE_COOKIE`. See [ADR 0002](docs/decisions/0002-spa-authentication.md) for the exact model. These settings are deployment preparation, not a production release configuration.

## Current status and next milestone

Phase 1.0 provides the platform foundation. Phase 1.1 adds first-party authentication. Phase 1.2 adds organization ownership, membership, isolation policies, and route-based onboarding. Phase 1.3 adds membership-scoped RBAC, permission-based organization updates, and owner-managed roles. Phase 1.3.5 introduces architecture guardrails before separately authorized application/module migration checkpoints. The overall refactor is not complete; **1.4 User invitations / organization users** remains planned and unimplemented. See the [roadmap](docs/phases/phase-01-core-platform.md), [Phase 1.3 validation](docs/phases/phase-01-rbac-validation.md), and [Phase 1.3.5 validation](docs/phases/phase-01-ddd-architecture-validation.md).
