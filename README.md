# CoreERP

CoreERP is a portfolio-grade ERP under active development, built to demonstrate disciplined software engineering: clear boundaries, automated verification, security, and maintainable architecture.

**Phase 1.4 — Organization Users, Membership Lifecycle & Invitations is implemented locally, awaiting review.** Identity still owns authentication; Organization owns tenant membership and RBAC under [ADR 0005](docs/decisions/0005-ddd-modular-monolith-architecture.md). Owners can invite users with roles, synchronize member roles, suspend/reactivate and remove members. Verified matching email is required to accept a hashed, expiring invitation. [ADR 0006](docs/decisions/0006-organization-membership-lifecycle-and-invitations.md) records lifecycle, delegation and concurrency decisions. **Phase 1.5B/C/D/E are approved, committed Audit baselines; Phase 1.5F Audit Trail UI & Browser Flow is complete locally, awaiting review.** All eleven approved organization, role, invitation and membership mutation facts record atomically; audit.view controls a tenant-scoped read API with bounded cursor pagination. The read-only Audit Trail UI provides filters, cursor pagination and expandable changes. See [ADR 0007](docs/decisions/0007-audit-trail-architecture.md) and [checkpoint validation](docs/phases/phase-01-audit-trail-validation.md).

## Architecture

**Phase 1.6B/C/D are approved and committed; Phase 1.6E — Notification Center UI & Browser Flow is complete locally for review.** Phase 1.6 means Notification Center foundation and remains incomplete. Storage and every consumer operation are private to organization/user/current membership era: suspension/reactivation retains history; removal and rejoin with a new membership do not restore the prior era. Successful invitation acceptance now requires an unread notification for the current persisted organization owner, committed atomically with business state and Audit. The current-organization bell opens a dedicated Notification Center with private history, explicit read actions and safe Users navigation. Independent Phase 1.6F review remains pending; no other producer is implemented. Generic email delivery, queues/Horizon, retries/outbox, realtime and preferences are deferred separately. See [ADR 0008](docs/decisions/0008-notification-center-architecture.md) and [validation](docs/phases/phase-01-notification-center-validation.md).

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

The schema includes users, password-reset/session scaffolding, organizations, memberships, organization roles, relational permission grants, membership status, invitations, invitation-role grants, Audit events and membership-era organization notifications. The sole ordinary notification producer is invitation acceptance → current owner, using the owner's active membership era and no credential/email/URL payload. There are no seeded users. Redis stores sessions, so the standard `sessions` table is unused. No Sanctum personal-access-token migration or token issuance is added.

- Frontend: <http://localhost:5174>
- API liveness: <http://localhost:8088/api/v1/health>
- API readiness: <http://localhost:8088/api/v1/ready>
- Mailpit inbox: <http://localhost:8026>

To try the application, open the frontend, create an account, then open Mailpit to follow its signed verification link. The link briefly opens the Laravel origin at port 8088 and returns to the SPA's organization onboarding screen. Create an organization, then enter its workspace at `/app/organizations/{organizationId}`. Open **Roles & Permissions** in the workspace to create or edit roles and their permissions. The explicit owner manages roles without needing an Owner role. Open **Users** to invite an email with optional roles, manage existing member roles, suspend/reactivate members, or remove membership. Owner membership cannot be suspended or removed. Delegated `members.view` allows member listing; `members.invite` allows invitations without roles. Password-reset messages also appear in Mailpit. Mailpit SMTP stays inside Docker on port 1025; its web UI binds only to loopback. Its inbox is ephemeral local development data.

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

`lint` checks Pint formatting without editing files; `format` applies formatting. Larastan runs at level 8 with no baseline or suppressed application errors. If an external deployment sets `AUTH_MODEL=App\Models\User`, change it to `App\Modules\Identity\Infrastructure\Eloquent\Models\User` and clear/rebuild Laravel configuration caches when deploying the namespace change. The repository examples use the default model and need no override.

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

## Invitation flow

Invitation emails arrive in Mailpit and expire after seven days. A new invitation to the same organization/email revokes the previous pending link. Existing active or suspended memberships cannot be invited again; reactivate a suspended member instead.

The invitee opens the email link, signs in or registers with the invited address, verifies their email, then explicitly accepts. The token is captured only in SPA memory and removed from the URL fragment. Open the verification link in another tab and return to **I've verified**; after a reload or same-tab verification, reopen the invitation email. No invitation token is stored in browser storage or returned by list APIs.

Suspension immediately hides the organization on subsequent access checks while retaining roles. Reactivation restores them; removal deletes only membership and its grants. Invitation mail is synchronous after commit, using SMTP only. On delivery failure, refresh the invitation list and reinvite to rotate the credential and retry.

## Current status and next milestone

Phase 1.0–1.3.5 establish the foundation, authentication, organizations, RBAC and modular architecture. Phase 1.4 adds organization users, membership lifecycle and invitations. See the [roadmap](docs/phases/phase-01-core-platform.md), [Phase 1.4 validation](docs/phases/phase-01-organization-users-validation.md), and [ADR 0006](docs/decisions/0006-organization-membership-lifecycle-and-invitations.md). **Phase 1.5B/C/D/E are approved and committed; Phase 1.5F Audit Trail UI & Browser Flow is complete locally, awaiting review.** Audit history API and frontend are implemented; Phase 1.5 remains awaiting review.


The audit history API is read-only: `GET /api/v1/organizations/{organization}/audit-events` requires authenticated verified access as an active owner or member with `audit.view`. Ordinary active members receive 403; suspended/nonmembers receive 404. Supported query parameters: `per_page` (1–100, default 25), opaque `cursor`, exact `action`, and paired `subject_type` / `subject_id`. Events expose stable identifiers, actor type/id, safe before/after snapshots, payload version and UTC time. Response `meta` contains `next_cursor`, `has_more`, `per_page`; no total count or actor PII lookup. Organization SHOW alone exposes `meta.can_view_audit`. See ADR 0007 for the cursor trust model and checkpoint validation for tests. The protected UI route is `/app/organizations/:organizationId/audit`; workspace navigation uses the capability. The page shows actor/subject IDs, UTC times and expandable changes, supports Apply/Clear filters, Refresh and Load more, and resets filters/pages on browser reload. Unsupported payload versions keep a safe fallback. History is never placed in browser storage or Pinia, and no audit mutation/export endpoint is added.

The Notification API requires authenticated verified active membership and exposes only the current recipient's current membership era, including for owners. Under `/api/v1/organizations/{organization}/notifications`: GET collection supports only `cursor` and `per_page` (default 25, 1–100); GET `/unread-count` returns `data.unread_count`; POST `/{notification}/read` and POST `/read-all` return 204. List metadata is `next_cursor`, `has_more`, `per_page`; data exposes stored plain snapshots/type/version, semantic target and microsecond UTC dates, without raw payload or recipient/tenant IDs. Hidden access wins over detailed validation; private foreign/old-era items return 404. Mark-read preserves the first timestamp; mark-all affects current-era unread rows visible to its statement, so later inserts may remain unread. C added no permissions/capability, migration, business producer or frontend change. D adds only required acceptance owner publication inside the business/Audit transaction. E consumes this unchanged API at `/app/organizations/:organizationId/notifications`: the shell bell and workspace Notifications link open a newest-first list with Load older, Refresh, Mark as read and Mark all as read. The shell shares only the current organization unread count, refreshed on visible entry/focus/visibility, read success and manual Refresh, with nonoverlapping visible-tab polling every 60 seconds. History updates only on page entry, manual Refresh and read-all; polling does not reorder it. Organization changes and detected 401/403/404 clear private state, and generation guards reject late successes and failures. Stored text is escaped, dates display UTC, and only supported version-1 organization.invitation_accepted with organization.users/null-ID maps to the current Users route. Unknown formats retain safe text without navigation. No rows/cursors enter Pinia or browser storage. The real Mailpit invitation-acceptance browser flow proves owner read persistence, recipient privacy and organization isolation. E is complete locally for review; Phase 1.6F remains pending and Phase 1.6 is incomplete.
