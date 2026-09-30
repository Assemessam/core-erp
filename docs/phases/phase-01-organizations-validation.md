# Phase 1.2 — Organizations and memberships validation

Date: 2026-09-30. Local Docker Compose validation on `feature/organizations`; no commit, push, branch change, reset, or staging operation was performed.

## Before implementation

The committed Phase 1.1 working tree was clean. The complete baseline backend suite passed 20 tests and 95 assertions; Pint and Larastan passed. The frontend suite passed 14 tests, ESLint, Prettier, TypeScript, and production build. Composer validation and both audits passed. The existing browser authentication test passed in the pinned Playwright container. Running Playwright inside the non-root frontend service initially hit a pre-existing root-owned `test-results/.last-run.json`; the pinned browser container with `CI=1` ran the test successfully without changing that artifact.

## Migration and architecture

`2026_09_30_000001_create_organizations_table.php` adds `organizations` with a ULID primary key, name, owner user foreign key and explicit owner index, and timestamps. `organization_memberships` has an internal numeric key, organization ULID and user foreign keys, timestamps, a unique `(organization_id, user_id)` key, and a user index. A deferred composite foreign key requires `(organization.id, owner_user_id)` to exist as a membership. This PostgreSQL constraint permits transactional insertion of the organization followed by its owner membership and prevents removal of the owner membership while the organization exists. No inactive membership state or RBAC fields exist.

The app uses shared-database, shared-schema tenancy. Organization IDs are public ULIDs, but every show/update request uses `OrganizationPolicy`. The list is membership-scoped. A member may view; only the explicit owner may update the name. Non-members get 404 for an existing or unknown organization; members denied an owner-only update get 403. Organization routes require a verified, authenticated user. The resource exposes only `id` and `name`; clients cannot submit `id` or `owner_user_id`. The SPA derives organization context from `/app/organizations/{organizationId}` and fetches it again on refresh. There is no stored active organization on the user or in browser storage. See [ADR 0003](../decisions/0003-multi-tenancy-and-organizations.md).

## Validation results

| Check | Observed result |
| --- | --- |
| Migration apply, rollback, reapply; status | Passed; organization migration is Ran in batch 2 |
| Backend complete suite | 28 passed, 152 assertions, including existing authentication and readiness tests |
| Pint | Passed, 51 PHP files |
| Larastan/PHPStan | Level 8 passed, 28 analyzed files, no errors |
| Frontend complete unit suite | 19 passed across 6 files |
| ESLint, Prettier, vue-tsc, Vite production build | All passed |
| Composer strict validation and platform requirements | Passed |
| Composer audit; npm audit at low threshold | No advisories; 0 vulnerabilities |
| Playwright pinned browser image | 2 passed: existing authentication flow and organization onboarding/refresh/second-user isolation |
| Compose configuration and startup | Valid; backend, frontend, PostgreSQL, Redis, Mailpit all healthy |
| HTTP and service probes | Direct health/readiness and frontend proxy readiness returned `{"data":{"status":"ok"}}`; PostgreSQL accepted connections, Redis returned PONG, Mailpit API responded |
| Git whitespace check | `git diff --check` passed |

Tests use real PostgreSQL constraints and transaction savepoints. They cover guest and unverified denial, creation, owner derivation, owner spoofing rejection, rollback on membership write failure, invalid names, membership-scoped lists, ordinary member view/403 update, non-member 404 view/update, minimal representation, duplicate membership, foreign keys, indexes, and owner-membership retention. Playwright registers and verifies two separate users through Mailpit, creates an organization for the first, refreshes its route, selects it again from the list, confirms API access, then verifies the second user sees an empty list and receives 404 in both UI and API.

## Commands executed

```sh
git branch --show-current
git status --short
docker compose ps
docker compose exec -T backend composer quality
docker compose exec -T frontend npm run quality
docker compose exec -T backend composer validate --strict
docker compose exec -T backend composer audit
docker compose exec -T frontend npm audit --audit-level=low
docker compose config --quiet
docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test
docker compose exec -T backend php artisan migrate --no-interaction
docker compose exec -T backend php artisan migrate:rollback --step=1 --no-interaction
docker compose exec -T backend php artisan test tests/Feature/OrganizationsTest.php
docker compose exec -T backend composer format
docker compose exec -T backend composer analyse
docker compose exec -T frontend npm run format
docker compose exec -T frontend npm run type-check
docker compose exec -T frontend npm test
docker compose exec -T backend composer check-platform-reqs
docker compose up -d --wait
docker compose exec -T backend php artisan migrate:status
docker compose exec -T backend php artisan route:list --path=api
curl --fail --silent --show-error http://localhost:8088/api/v1/health
curl --fail --silent --show-error http://localhost:8088/api/v1/ready
curl --fail --silent --show-error http://localhost:5174/api/v1/ready
curl --fail --silent --show-error http://localhost:8026/api/v1/info
docker compose exec -T postgres pg_isready -U coreerp
docker compose exec -T redis redis-cli ping
git diff --check
git diff --stat
```

An intermediate concurrent backend/Playwright run failed tests that assumed a globally empty development database; Playwright created an organization during that run. The tests were corrected to scope counts to their own users/organizations. The final backend suite passed on the populated database. One intermediate browser run failed because the helper searched a mixed-case email while registration normalizes it; the helper was corrected and the final suite passed. The first migration rollback attempt followed a local edit that introduced a new constraint after the migration had already run; the rollback was made tolerant of that local schema state, then rollback and reapply succeeded. These intermediate failures are resolved.

## Security review and limitations

IDOR and tenant enumeration: route-model binding does not grant access; the policy returns 404 to non-members. The list uses a membership predicate and never queries all organizations for its response. Owner-only updates distinguish a known member (403) from a non-member (404). Form Requests prohibit client-supplied IDs and owner references; creation derives the owner from the authenticated session. Database constraints enforce uniqueness, references, and owner membership. The frontend route and its ID are untrusted context; direct API calls apply the same checks. Browser and backend isolation tests confirm these properties for the current endpoints.

No roles, permissions, invitation or membership management, organization deletion, ownership transfer, audit trail, or ERP records exist. Future tenant-owned tables require their own organization foreign keys, scoped queries, policies, and cross-tenant tests. The local Compose stack is not a production deployment. GitHub-hosted CI has not run because this work remains uncommitted. Browser tests created local development users, an organization, and Mailpit messages.
