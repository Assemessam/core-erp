# Phase 1.3.5A — Architecture Guardrails and Characterization

This first section is the historical checkpoint-A record. The checkpoint-B record follows below.

- Date: 2026-09-30
- Status: Checkpoint A complete against local validation; the overall Phase 1.3.5 refactor is **not complete**.
- Scope: Documentation, architecture guardrails, and characterization only. Phase 1.3.5B and Phase 1.4 have not started.

## Approved architecture and scope

[ADR 0005](../decisions/0005-ddd-modular-monolith-architecture.md) records the approved DDD-oriented modular monolith, pragmatic Application layer, and CQRS-lite direction. Identity owns authentication/identity. Organization owns organization identity, ownership, memberships, and tenant RBAC together. Domain is framework-independent; same-module Eloquent and Laravel transactions remain allowed in lightweight Application paths. Rich aggregates/repositories need real invariants, not ceremony.

Acceptance remains the same business behavior, HTTP API, frontend, database, and tenant/security semantics with different backend architecture. In A, no production architecture has moved yet: there are no module folders, evaluator/stubs, repositories, domain events, shared kernel, bus, or new business features.

## Inspection and baseline

The working tree was clean. Reviewed root guidance, the previous architecture review, current architecture/phase documentation, application authorization and Fortify wiring, requests/resources/routes, database-test setup, and installed Pest architecture/parser implementations. The existing Compose services were stopped; `docker compose up -d --wait` started the existing stack successfully without rebuilding, installing dependencies, or migrating.

Before changes, `composer test` passed **39 tests / 256 assertions**, including the real readiness integration test. Database tests were sequential. No backend database test overlapped browser writes.

## Files changed

| File | Change |
| --- | --- |
| `AGENTS.md` | Concise progressive architecture rules; existing scope/git restrictions retained |
| `README.md` | Current checkpoint, ADR link, and incomplete overall-refactor status |
| `docs/decisions/0005-ddd-modular-monolith-architecture.md` | Approved architecture, exceptions, compatibility, rollout, testing, and trade-offs |
| `docs/architecture/system-overview.md` | Current versus target structure, guardrails, deferred 404/CORS/testing concerns |
| `docs/phases/phase-01-core-platform.md` | Insert 1.3.5 between RBAC and 1.4; mark A only complete |
| `backend/phpunit.xml` | Architecture suite included in normal backend quality discovery |
| `backend/tests/Architecture/BoundariesTest.php` | Five active checks and two explicitly deferred layer checks |
| `backend/tests/Feature/ArchitectureCharacterizationTest.php` | Ten test cases for wiring and HTTP/security contracts |
| `backend/tests/Feature/RbacTest.php` | One additional resource/permission-ordering/can_manage test |
| This validation record | Evidence, commands, limitations, and next-checkpoint boundary |

## Architecture guardrails

The suite uses the existing `pestphp/pest-plugin-arch` package. No packages/manifests/lockfiles changed. It does not boot Laravel or use the database.

Active checks:

1. Controllers have no direct DB facade, database manager, connection-interface, or PDO dependencies.
2. Existing persistence models have no application HTTP-delivery dependencies.
3. Existing actions/models/policies/enums do not read ambient session/request context through the checked helpers/session types.
4. Controllers contain no explicit transaction/beginTransaction/commit/rollBack calls, including calls through model connections.
5. Application source introduces no PHP `global` or `$GLOBALS` access.

The last two checks inspect PHP syntax using the already-installed parser; they do not use regex. Domain-framework/outer-layer and Application-HTTP dependency tests are **explicitly skipped**, with explanatory output, until module layer directories exist. They discover the conventional module paths and activate on introduction. No fake modules or stubs were created to make them pass.

Static limits are intentional: these checks do not prove tenant predicates, detect every indirect mutation/dynamic transaction call, or rule out every singleton/cache/session-based tenant resolver. Current controller Eloquent updates remain existing behavior, not newly compliant Application code. Migration checkpoints must extend cross-module and legacy/new dependency rules alongside security tests.

## Characterization coverage

New tests cover policy resolution, User-to-factory and factory-to-User mapping, auth-provider model configuration, five Fortify contract bindings, and the exact public unverified `/api/v1/me` representation. Existing authentication tests already cover verified `/me`, session login/logout, recovery, verification, and throttling.

An actor matrix sends invalid payloads to organization update and all four RBAC paths. Guest, unverified member, outsider, and ordinary member receive the correct denial before input validation. Tests assert message-only envelopes and application-owned denial text, not incidental framework wording.

Missing/hidden organization tests protect 404 and its JSON envelope. Nested-role tests prove that an owner of both organizations cannot bind the other organization's role, that absent/foreign roles return 404 before validation, and that a local role still updates successfully. Invalid requests expose field errors; duplicate role names preserve the application-owned message and do not mutate records.

The added RBAC resource test submits both permissions in reverse order and checks sorted keys on POST (201), PATCH (200), and listing, including exact data envelopes and owner/member `meta.can_manage` values. Existing organization creation/update tests already assert 201/200, exact id/name resources, ownership protection, and rollback; these were retained rather than duplicated.

## Observed HTTP/security behavior

| Situation | Existing result | Test contract |
| --- | --- | --- |
| Guest organization/RBAC request | 401 | Status and message-only JSON; no validation errors |
| Unverified member | 403 | Status and message-only JSON; no validation errors |
| Verified non-member | 404 | Status and message-only JSON; no validation errors |
| Member lacking update/read authority | 403 | `You do not have permission for this action.` |
| Member attempts role mutation | 403 | `Only the organization owner may manage roles.` |
| Missing organization or foreign/absent nested role | 404 | Status and nonempty message-only JSON; no framework class-name assertion |
| Invalid role name and permission | 422 | `message`, field-error arrays for `name` and `permissions.0`; no mutation |
| Duplicate trimmed/case-insensitive role name | 422 | `A role with this name already exists in this organization.` in message and `errors.name` |
| Organization creation | 201 | Exact `data: {id, name}` |
| Role creation/update | 201/200 | Exact `data: {id, name, permissions}` with sorted keys |
| Role listing | 200 | Same resource plus `meta.can_manage` true for owner, false for authorized member |
| Unverified `/api/v1/me` | 200 | Only id, name, email, and `email_verified: false` |

### Actual 404 body differences

A one-off PHP HTTP-kernel probe booted the existing application with debug disabled, created fixture rows inside an explicit transaction, and rolled that transaction back in `finally`. Actual responses were:

```json
{"message":"Not Found"}
```

for an existing policy-hidden organization, and:

```json
{"message":"No query results for model [App\\Models\\Organization] 01AAAAAAAAAAAAAAAAAAAAAAAA"}
```

for the absent organization identifier. A foreign nested role returned the same Laravel missing-model pattern with `App\\Models\\Role` and the requested role ULID.

These bodies are distinguishable even with debug disabled. Current 404 semantics therefore establish status-level hiding, not indistinguishable responses. No normalization occurred. PHP class names, requested identifiers embedded in text, and the body difference itself are not frozen as intended API contracts. Future namespace moves must review this behavior; normalizing tenant 404 messages requires a separate explicit security/API decision.

### CORS deployment follow-up

An OPTIONS request from the configured allowed origin, asking for PATCH, returned 204 with:

```text
Access-Control-Allow-Origin: http://localhost:5174
Access-Control-Allow-Credentials: true
Access-Control-Allow-Methods: GET, POST, OPTIONS
```

PATCH is absent. A browser using a true cross-origin PATCH would reject the preflight permission. Local SPA traffic uses Vite's same-origin proxy, and all established browser flows passed. This checkpoint does not broaden supported deployment behavior: `config/cors.php` remains unchanged. Review PATCH support with the cross-origin deployment configuration separately.

## Validation results

| Check | Result |
| --- | --- |
| Complete backend quality | **55 passed, 2 explicitly skipped, 507 assertions** |
| Architecture alone | **5 passed, 2 explicitly skipped, 54 assertions** |
| New characterization file alone | 10 passed, 189 assertions |
| Pint | Passed, 64 PHP files; no formatter changes needed |
| Larastan/PHPStan | Level 8, 38 application files, no errors |
| Composer strict validation | Valid |
| Composer platform requirements | All satisfied in PHP 8.5.11 container |
| Composer audit | No security vulnerability advisories |
| Frontend quality | ESLint, Prettier, type checking, 28 Vitest tests across 7 files, and production build passed |
| npm audit | 0 vulnerabilities |
| Full established Playwright | **3 passed** in pinned v1.63.0 browser image |
| Compose | Valid configuration; all five services healthy |
| Readiness | PostgreSQL accepts connections; Redis PONG; direct liveness/readiness and Vite readiness proxy return `{"data":{"status":"ok"}}` |
| Mailpit | API responds, v1.27.1 |
| Migration status / routes | Existing three migrations Ran; same 11 API routes |
| Git | Whitespace check passed; migration and production-code diffs empty |

The complete backend result includes five active architecture checks and eleven added Feature cases. The only skipped tests are clearly identified future Domain/Application checks; no existing behavior test was skipped. Browser execution retained the established configuration (two workers); no parallel backend/database test execution was introduced.

## Commands executed

Inspection used `git status`, `git diff`, `git ls-files`, `cat`, and `rg`, including reads of installed framework/Pest implementations. Edits used patches. Substantive validation commands ran from the repository root:

```sh
docker compose config --quiet
docker compose up -d --wait
docker compose ps
docker compose exec -T backend composer test
docker compose exec -T backend php vendor/bin/pest --testsuite=Architecture
docker compose exec -T backend php vendor/bin/pest tests/Feature/ArchitectureCharacterizationTest.php
docker compose exec -T backend php vendor/bin/pint --test
docker compose exec -T backend composer quality
docker compose exec -T backend composer validate --strict
docker compose exec -T backend composer check-platform-reqs
docker compose exec -T backend composer audit
docker compose exec -T frontend npm run quality
docker compose exec -T frontend npm audit --audit-level=low
docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test
docker compose exec -T postgres pg_isready -U coreerp
docker compose exec -T redis redis-cli ping
curl --fail --silent --show-error http://localhost:8088/api/v1/health
curl --fail --silent --show-error http://localhost:8088/api/v1/ready
curl --fail --silent --show-error http://localhost:5174/api/v1/ready
curl --fail --silent --show-error http://localhost:8026/api/v1/info
docker compose exec -T backend php artisan migrate:status
docker compose exec -T backend php artisan route:list --path=api
curl --silent --show-error -i -X OPTIONS http://localhost:8088/api/v1/organizations/01AAAAAAAAAAAAAAAAAAAAAAAA -H 'Origin: http://localhost:5174' -H 'Access-Control-Request-Method: PATCH' -H 'Access-Control-Request-Headers: content-type'
git diff --check
git diff -- backend/database/migrations
git diff -- backend/app backend/routes backend/config frontend backend/composer.json backend/composer.lock
git status --short
git diff --stat
git ls-files --others --exclude-standard
```

The 404 probe used `docker compose exec -T backend php` with a script on stdin: bootstrap the console kernel, disable debug, begin a transaction, create two organizations for one owner and an outsider, dispatch GET hidden/missing organization and PATCH foreign-role requests through the HTTP kernel, print status/body, and roll back. It did not add a file or alter application code. Intermediate architecture execution passed four active checks before the fifth guard was added; final totals above include all changes.

## Limitations and deferred work

- Results are local, not a GitHub-hosted CI run or production deployment.
- Tests retain the existing configured PostgreSQL/DatabaseTransactions setup. One existing test temporarily drops/reapplies RBAC schema inside its outer rollback transaction; no migrations were added, edited, or run as deployment operations. Do not overlap this with other database writers or introduce parallel backend execution without an isolation review.
- Playwright creates local users, organizations, roles, and Mailpit messages. Generated ignored build/test/cache artifacts are not source changes. Application/database behavior and schema are unchanged; fixture activity is not a claim of byte-identical database contents.
- Static architecture checks have the limits described above and in ADR 0005. Namespace migration will need additional checks, explicit provider/policy wiring, and factory updates.
- 404 body normalization, cross-origin PATCH configuration, and future database-test isolation improvements remain separate decisions.

No namespace migration occurred. No business logic, authorization evaluator, route, HTTP representation, frontend source, database schema, migration history, dependency manifest, or lockfile changed. `git diff -- backend/database/migrations` is empty. No commit, push, branch switch, reset, or discard operation occurred.

## Manual review and next checkpoint

Review ADR 0005's lightweight Application/Eloquent exception, context ownership, dependency rules, and static-analysis limitations; the characterization matrix and 404/CORS observations; and the architecture tests' activation conditions. Untracked new files are listed by Git status but do not appear in plain `git diff --stat` until staged; no staging was performed.

Recommended Phase 1.3.5B scope, only after approval: extract current Organization controller reads/rename into small Application entry points with unchanged namespaces/binding and acceptance behavior where feasible, before a separately reviewed mechanical module move. Confirm that checkpoint's exact boundary first. Stop after A; do not start B or Phase 1.4.

# Phase 1.3.5B — Extract Existing Organization Application Entry Points

- Date: 2026-09-30
- Status: Checkpoint B complete against local validation. Overall Phase 1.3.5 remains in progress; C has not started.
- Starting state: clean working tree on `refactor/ddd-architecture`, at committed checkpoint A, `9591194` (`refactor: establish DDD architecture guardrails`). No branch switch or Git mutation was performed.

## Scope and implementation

Added `App\Queries\ListOrganizations::handle(int $userId)` and `App\Actions\RenameOrganization::handle(Organization $organization, string $name): Organization`. These are temporary conventional namespaces before module migration, not new bounded contexts. No existing class moved. ADR 0005 remains unchanged because this implements its already-approved pragmatic exception.

ListOrganizations contains the exact previous database read: `whereHas('memberships', ... user_id ...)`, `orderBy('name')`, then `get()`. It still selects the current model data, is unpaginated, and adds no eager loading (the existing id/name Resource needs none). Filtering stays in PostgreSQL, not PHP. OrganizationController obtains the actor from the authenticated request and passes its integer ID; it never accepts an actor ID from client input. The query uses neither a User model nor ambient auth/request state.

RenameOrganization performs the existing Eloquent update of the validated name and returns the same model. It intentionally accepts the already-authorized route-bound Organization: this preserves binding/authorization order and avoids another database lookup. It adds no validation, Gate/Policy call, HTTP error, transaction, event, ownership change, or nonstandard timestamp behavior. Validation and actor authorization **remain the caller's responsibility**, currently UpdateOrganizationRequest and OrganizationPolicy. This transitional operation must not be treated as an independently authorized public workflow before later authorization centralization.

Controller index/update now adapt HTTP input, invoke the operations, and return the existing Resources. Store/CreateOrganization and show/Gate/route-bound representation are unchanged. RBAC controller/actions, membership permission evaluation, Policies, Form Requests, Resources, routes, and models are unchanged. No repository, mapper, DTO, value object, pure aggregate, or authorization evaluator was needed. No extra lookup or transaction was introduced for rename.

## Files changed for B

| File | Change |
| --- | --- |
| `backend/app/Queries/ListOrganizations.php` | New explicit read query |
| `backend/app/Actions/RenameOrganization.php` | New lightweight write operation, documenting caller authorization/validation |
| `backend/app/Http/Controllers/OrganizationController.php` | Delegate index query and update mutation |
| `backend/tests/Feature/Application/OrganizationOperationsTest.php` | Six direct-operation/HTTP boundary cases, using the existing real PostgreSQL transaction setup |
| `backend/tests/Architecture/BoundariesTest.php` | New operation dependency guard and controller mutation syntax guard |
| `README.md` | Current checkpoint B status |
| `docs/architecture/system-overview.md` | Actual current extraction and transitional boundaries |
| `docs/phases/phase-01-core-platform.md` | B complete, C not started; overall refactor still incomplete |
| This record | Separate B implementation/validation evidence; A preserved as history |

## Tests and guardrails

The pre-edit Organization/RBAC/characterization/Architecture baseline passed **35 tests / 412 assertions**, with the same two deferred module-layer skips.

New tests prove:

- Explicit actor membership scoping includes both owned and non-owned member organizations, excludes unrelated organizations, and preserves name ordering, including when the ambient authenticated user differs or no user is authenticated.
- A user with zero memberships gets an empty collection even while an unrelated owner is authenticated.
- Client-supplied `user_id` cannot override the session actor in the HTTP list endpoint.
- Rename works directly without HTTP objects/authentication, returns the supplied model, persists its name, and preserves owner, memberships, roles, permissions, and membership-role links.
- Empty/overlong rename input is still rejected by the HTTP validation boundary without mutation.

No existing feature/characterization assertions were weakened or changed. Tests stay under Feature/Application to inherit the existing PostgreSQL DatabaseTransactions setup; no testing strategy or parallel backend execution was introduced.

The new dependency rule actively checks the two operations now, without waiting for module namespaces. It rejects application HTTP classes, framework HTTP types/exceptions, ValidationException, auth contracts/facades, Gate/Session, and ambient HTTP/auth helpers. A PHP-parser syntax guard rejects common explicit Eloquent/query-builder mutation calls in controllers. It complements the earlier DB/transaction checks. This is not regex or complete data-flow analysis: dynamic/indirect calls and unlisted mutation methods still need review and behavior tests. Existing two future module-layer checks remain explicitly skipped; no modules/stubs were created.

An initial Pint run identified import order in the architecture test; formatting that file resolved it. An initial architecture scan of the entire Symfony HttpKernel namespace loaded a deprecated vendor extension. The new rule was narrowed to the relevant HTTP exception namespace, avoiding unrelated vendor loading without suppressing warnings. Final validation has no such deprecation.

## Results

| Check | Result |
| --- | --- |
| Complete backend quality | **63 passed, 2 expected skips, 570 assertions** |
| Architecture suite separately | **7 passed, 2 expected skips, 96 assertions** |
| Pint | Passed, 67 files |
| Larastan/PHPStan | Level 8, 40 analyzed application files, no errors |
| Composer strict validation/platform requirements | Passed |
| Composer audit | No vulnerability advisories |
| Established frontend quality | ESLint, Prettier, type checking, 28 Vitest tests, production build passed |
| Full pinned Playwright suite | **3 passed**, existing two-worker browser configuration |
| Infrastructure | Compose config valid; all five existing services healthy; PostgreSQL ready, Redis PONG, direct health/readiness and Vite readiness return `status: ok` |
| Git checks | Whitespace check passed; migration and frontend diffs empty |

Backend database testing finished before browser writes. Local tests do not establish GitHub-hosted CI or production deployment results. Existing migration-compatibility tests temporarily manipulate schema inside rollback transactions; no deployment migration or schema change was introduced. Browser tests create the normal local test accounts, organizations, roles, and Mailpit messages; otherwise no data changes were performed. The established testing environment and the 404/CORS follow-ups recorded in A remain unchanged.

## Commands executed for B

Inspection used `cat`, `rg`/existing repository context, `git branch --show-current`, `git status --short`, `git log -1 --oneline`, and `git diff`. Substantive validation:

```sh
docker compose exec -T backend php vendor/bin/pest tests/Feature/OrganizationsTest.php tests/Feature/RbacTest.php tests/Feature/ArchitectureCharacterizationTest.php tests/Architecture
docker compose exec -T backend php vendor/bin/pest tests/Feature/Application/OrganizationOperationsTest.php tests/Feature/OrganizationsTest.php tests/Feature/ArchitectureCharacterizationTest.php tests/Architecture
docker compose exec -T backend php vendor/bin/pint --test
docker compose exec -T backend php vendor/bin/pest --testsuite=Architecture --display-deprecations
docker compose exec -T backend php vendor/bin/pint tests/Architecture/BoundariesTest.php
docker compose exec -T backend composer quality
docker compose exec -T backend php vendor/bin/pest --testsuite=Architecture
docker compose exec -T backend composer validate --strict
docker compose exec -T backend composer check-platform-reqs
docker compose exec -T backend composer audit
docker compose exec -T frontend npm run quality
docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test
docker compose config --quiet
docker compose ps
docker compose exec -T postgres pg_isready -U coreerp
docker compose exec -T redis redis-cli ping
curl --fail --silent --show-error http://localhost:8088/api/v1/health
curl --fail --silent --show-error http://localhost:8088/api/v1/ready
curl --fail --silent --show-error http://localhost:5174/api/v1/ready
git diff --check
git diff -- backend/database/migrations frontend
git status --short
git diff --stat
```

## Compatibility, review, and stop point

Migrations, constraints, schema, frontend source, routes, resource shapes, authorization/verification behavior, 401/403/404 ordering, ownership/RBAC semantics, and CreateOrganization are unchanged. Existing feature and browser tests remain green. No dependency changes, namespace migration, Modules directories, or Phase 1.4 work. No commit, push, switch, reset, discard, or staging operation.

Review the query's explicit actor ID and unchanged SQL membership predicate; the rename operation's already-authorized Eloquent input and caller-validation precondition; controller delegation; and the syntax guard's documented limits. New files are untracked and therefore excluded from plain `git diff --stat` until staged.

Recommended C: a separately approved mechanical Organization namespace migration with provider/policy, relationship, import, and architecture-rule updates and the same acceptance tests. Keep authorization centralization and RBAC behavior extraction separately reviewable. **Stop after B; C has not begun.**
