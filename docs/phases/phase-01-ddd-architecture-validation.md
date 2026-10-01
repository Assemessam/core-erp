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

# Phase 1.3.5C — Mechanical Organization Module Namespace Migration

- Date: 2026-09-30–2026-10-01
- Status: C complete against local validation. Overall 1.3.5 remains in progress; D and Phase 1.4 have not started.
- Baseline: clean `refactor/ddd-architecture`, `5eba16b` (`refactor: extract organization application operations`). No Git staging, commit, push, branch switch, reset, or discard.

## Scope and file ownership

Moved 19 existing classes, preserving their bodies, into `backend/app/Modules/Organization/`:

```text
Domain/Authorization/PermissionKey.php
Application/Commands/CreateOrganization.php
Application/Commands/RenameOrganization.php
Application/Commands/SaveRole.php
Application/Operations/AssignMembershipRole.php
Application/Queries/ListOrganizations.php
Infrastructure/Eloquent/Models/Organization.php
Infrastructure/Eloquent/Models/OrganizationMembership.php
Infrastructure/Eloquent/Models/Role.php
Infrastructure/Eloquent/Models/Permission.php
Infrastructure/Authorization/OrganizationPolicy.php
Infrastructure/Providers/OrganizationServiceProvider.php  (new)
Presentation/Http/Controllers/OrganizationController.php
Presentation/Http/Controllers/OrganizationRoleController.php
Presentation/Http/Requests/StoreOrganizationRequest.php
Presentation/Http/Requests/UpdateOrganizationRequest.php
Presentation/Http/Requests/SaveRoleRequest.php
Presentation/Http/Resources/OrganizationResource.php
Presentation/Http/Resources/RoleResource.php
Presentation/Http/Resources/PermissionResource.php
```

Original locations were respectively `app/Enums`, `app/Actions` (commands/operation), `app/Queries`, `app/Models`, `app/Policies`, and `app/Http/{Controllers,Requests,Resources}`. No aliases or compatibility classes remain. A comparison against Git HEAD confirmed identical moved class bodies after excluding namespaces, imports, and whitespace.

The new OrganizationServiceProvider only registers `Gate::policy(Organization::class, OrganizationPolicy::class)`. `bootstrap/providers.php` registers it explicitly. `phpstan.neon` adds bootstrap/providers.php to analysis; level 8 stays unchanged. Existing App PSR-4 mapping suffices; Composer manifests/lockfiles are unchanged.

Other modified files: `app/Models/User.php` (membership import only), `routes/api.php` (two controller imports only), `tests/Architecture/BoundariesTest.php`, four existing Feature test files (OrganizationsTest, RbacTest, ArchitectureCharacterizationTest, Application/OrganizationOperationsTest; imports/order only), README, system overview, roadmap, and this record. ADR 0005 and AGENTS.md remain unchanged.

Eloquent tables, keys, ULIDs, fillable attributes, relationships, casts, binding and persistence logic are unchanged. Organization and OrganizationMembership now explicitly import the existing User model; User still exposes its inverse relationship. Controllers import the existing base Controller. Policy logic remains unchanged; no access evaluator was added. Domain contains only the pure PermissionKey vocabulary. Identity, Fortify, platform code, factories, frontend and migrations remain in place.

## Architecture checks and temporary dependencies

Both previously skipped Domain/Application rules now activate. Model HTTP-dependency and business ambient-context checks include real module namespaces. Controller transaction/mutation checks discover the new Presentation directory; B's extracted-operation checks still apply. Same-module Eloquent and DB transactions remain permitted.

Narrow legacy exceptions preserve behavior instead of changing it during movement:

- Only SaveRole may use `abort_if` in Application (its existing foreign-role 404).
- Only SaveRole and AssignMembershipRole may use ValidationException in Application.
- Only CreateOrganization may depend on `App\Models\User` in Application; it reads the supplied owner's key, with no Identity mutation.
- Infrastructure owner/user relations and OrganizationPolicy retain the existing User dependency. Presentation uses User for authenticated input assertions; User's inverse relation points to the moved membership class.

The Application HTTP scan targets Symfony HttpFoundation and HttpKernel exceptions rather than scanning unrelated HttpKernel extensions, matching B's avoidance of vendor deprecation loading. No broad class exclusion removes SaveRole from other HTTP rules. These are dependency/syntax guards, not data-flow proofs: indirect mutations, dynamic calls, and accessing Identity through relationships still need review and behavioral tests. No new package, generic abstraction, or domain invariant extraction.

## Incremental checks and compatibility

- Baseline targeted Organization/RBAC/characterization/Application/Architecture: **43 passed, 2 skipped, 475 assertions**.
- After Domain movement: RBAC/Architecture **20 passed, 1 skipped, 252 assertions**; Larastan passed.
- After Application movement: Organization/RBAC/Application/Architecture **35 passed, 366 assertions**; Larastan passed.
- Models and Policy/provider moved as one coherent wiring step so policy discovery was not left broken: Organization/RBAC/characterization/Application **36 passed, 379 assertions**.
- After Presentation movement: full backend suite and static analysis passed as below.

Route inspection ran before and after migration. The temporary pre-move route JSON did not survive the interrupted session; comparison against the committed route source confirmed all definitions byte-identical after excluding imports. The post-move table has the same 11 API entries, methods, paths and middleware; only Organization controller action namespaces changed. Existing scoped-binding and security tests pass.

The API retains organization/role creation 201, update 200, exact Resource envelopes/fields, permission ordering and `meta.can_manage`. Characterization retains guest 401, unverified 403, non-member 404, unauthorized member 403, foreign nested role 404, validation 422 and application-owned duplicate/authorization messages. Existing transaction, locking, rollback, permission revocation and composite/deferred constraint tests pass unchanged.

With debug disabled, exception-renderer inspection confirms missing-model text now contains `App\Modules\Organization\Infrastructure\Eloquent\Models\Organization` or `Role`. Policy-hidden errors remain `{"message":"Not Found"}`. This incidental framework namespace change is intentionally not frozen by assertions or hidden behind compatibility aliases. Status/security/envelope semantics remain intact; 404 normalization remains separate work.

## Final results

| Check | Result |
| --- | --- |
| Complete Pest suite | **65 passed, 653 assertions**, no skips |
| Architecture separately | **9 passed, 179 assertions**, no skips |
| Pint | Passed, 68 files |
| Larastan | Level 8, 42 files, no errors |
| Composer optimized strict PSR autoload | Passed, package discovery successful |
| Composer strict validate / platform requirements | Passed |
| Composer audit | No security vulnerability advisories |
| Frontend quality | ESLint, Prettier, vue-tsc, **28 Vitest tests / 7 files**, build passed |
| npm audit | 0 vulnerabilities |
| Established Playwright | **3 passed**, pinned image, existing 2 browser workers |
| Compose/readiness | Config valid; all 5 services healthy; PostgreSQL accepting connections, Redis PONG, health/API/proxy status ok, Mailpit HTTP 200 |
| Migration status | All three historical migrations Ran; no migration executed |
| Git | Whitespace check clean; migration/frontend diffs empty |

## Commands executed

Inspection used `cat`, `rg`, `git branch --show-current`, `git status --short`, `git log -1 --oneline`, `git show HEAD:<path>`, and `git diff`. Temporary Python scripts mechanically moved files/replaced imports and compared moved class bodies and route definitions against the committed baseline. They are not repository tools or runtime dependencies.

```sh
docker compose exec -T backend php artisan route:list --path=api --json
# Initial baseline:
docker compose exec -T backend php vendor/bin/pest tests/Feature/OrganizationsTest.php tests/Feature/RbacTest.php tests/Feature/ArchitectureCharacterizationTest.php tests/Feature/Application tests/Architecture
# Incremental Domain and Application checks:
docker compose exec -T backend php vendor/bin/pest tests/Feature/RbacTest.php tests/Architecture --compact
docker compose exec -T backend php vendor/bin/pest tests/Feature/OrganizationsTest.php tests/Feature/RbacTest.php tests/Feature/Application tests/Architecture --compact
docker compose exec -T backend composer analyse
# Model/Policy step:
docker compose exec -T backend php vendor/bin/pest tests/Feature/OrganizationsTest.php tests/Feature/RbacTest.php tests/Feature/ArchitectureCharacterizationTest.php tests/Feature/Application --compact
docker compose up -d --wait
docker compose exec -T backend php vendor/bin/pint app/Modules app/Models/User.php bootstrap/providers.php routes/api.php tests/Architecture/BoundariesTest.php tests/Feature/Application/OrganizationOperationsTest.php tests/Feature/ArchitectureCharacterizationTest.php tests/Feature/OrganizationsTest.php tests/Feature/RbacTest.php
docker compose exec -T backend composer dump-autoload --optimize --strict-psr
docker compose exec -T backend composer quality
docker compose exec -T backend php vendor/bin/pest --testsuite=Architecture --compact
docker compose exec -T backend composer validate --strict
docker compose exec -T backend composer check-platform-reqs
docker compose exec -T backend composer audit
docker compose exec -T backend php artisan migrate:status
docker compose exec -T frontend npm run quality
docker compose exec -T frontend npm audit
docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test
docker compose config --quiet
docker compose ps
docker compose exec -T postgres pg_isready -U coreerp
docker compose exec -T redis redis-cli ping
curl --fail --silent --show-error http://localhost:8088/api/v1/health
curl --fail --silent --show-error http://localhost:8088/api/v1/ready
curl --fail --silent --show-error http://localhost:5174/api/v1/ready
curl --fail --silent --show-error -o /dev/null -w 'Mailpit HTTP %{http_code}\n' http://localhost:8026/
git diff --check
git diff -- backend/database/migrations
git diff -- frontend
git diff --find-renames
git status --short
git diff --stat
```

A read-only PHP stdin probe booted Laravel and rendered ModelNotFoundException/AuthorizationException with debug disabled to inspect incidental 404 text. Initial command corrections: host `python` was unavailable (used `python3`); after resumption temporary files/process handles were gone and services stopped (inspected retained workspace and started the existing stack); Pint `--dirty` could not see Git inside the backend mount (used explicit changed paths). None required business logic changes. Formatting only reordered imports. Final validations supersede these setup failures.

## Limitations, review and stop point

No intentional Organization/RBAC logic, authorization, route binding, transaction, database schema or frontend changes. `git diff -- backend/database/migrations` and `git diff -- frontend` are empty. Historical migrations reference schema definitions rather than moved PHP classes; no history rewrite or reseed. Browser tests create ordinary test accounts/organizations/roles/email; database contents are not claimed byte-identical. Backend database tests stayed sequential and finished before browser writes. Existing PostgreSQL transaction/schema-test cautions and cross-origin PATCH CORS follow-up remain unchanged; no deployment fix mixed in.

Review explicit provider registration, User relationship imports, the three narrow Application exception pairs, and new module files. Plain unstaged Git diff shows deletions and excludes new module files; inspect untracked files alongside it (no staging solely for rename detection). Local checks do not establish production or hosted-CI results.

Recommended D, subject to separate approval: a focused Organization authorization-boundary extraction with adversarial isolation/revocation tests; separately decide legacy HTTP error translation and the membership tenant invariant. Do not combine this with Identity migration or Phase 1.4. **C stops here; D has not begun.**

# Phase 1.3.5D — Centralize Organization Authorization

- Date: 2026-10-01
- Status: D complete against local validation; later 1.3.5 checkpoints and Phase 1.4 have not started.
- Baseline: clean `refactor/ddd-architecture`, `4ef7b49` (`refactor: move organization context into module`). No staging, commits, pushes, branch changes, resets or discarded work.

## Design and centralized rules

OrganizationAccess is the single Application evaluator used by OrganizationPolicy and authorized write use cases. Its four methods accept trusted explicit integer actor ID and string organization ID; it never reads ambient identity or invokes Gate/Policy/HTTP code.

| Ability | Rule after persisted membership is established |
| --- | --- |
| view | Membership allows |
| update | Explicit persisted owner or union of roles granting organizations.update |
| viewRoles | Explicit persisted owner or union of roles granting roles.view |
| manageRoles | Explicit persisted owner only |

No membership (or missing organization) yields HIDDEN. Membership with insufficient authority yields FORBIDDEN. Owners require membership too; the existing deferred owner-membership FK still enforces this at commit. No Owner role, synthetic grants, new catalog keys, caching, or member-administration capabilities.

AccessDecision is a small immutable result with a private constructor, named factories and three outcomes: ALLOWED, HIDDEN, FORBIDDEN. Forbidden decisions carry the existing message. `requireAllowed()` throws the module-specific AccessDenied runtime exception, carrying the decision without HTTP status/response dependencies.

OrganizationPolicy now only passes IDs to the evaluator and adapts its result through Infrastructure/Authorization/AccessResponse. It no longer queries membership, ownership, roles or permissions. AccessResponse maps allowed/hidden/forbidden to Laravel allow/denyAsNotFound/deny. bootstrap/app.php registers one exception mapping, only for AccessDenied, through that same adapter into AuthorizationException. Laravel's existing JSON renderer preserves HIDDEN → 404 `Not Found`; FORBIDDEN → 403 with the existing capability or owner-only message. Unrelated exception behavior is untouched.

OrganizationMembership::hasPermission was removed, with its tests ported to the evaluator's explicit actor API. Membership models retain persistence relationships only. The evaluator loads persisted membership/ownership using one scoped organization query with a membership EXISTS predicate; non-owner permission checks use a second EXISTS query over tenant-filtered memberships, roles and permission keys. This preserves multiple-role unions and ignores stale/spoofed models and loaded relationship collections. Ownership comes from persisted organizations.owner_user_id. There is no per-role iteration or cache; each decision costs one or two queries. No brittle SQL/count assertions were added.

## Protected writes and unchanged boundaries

RenameOrganization and SaveRole now require an explicit actor ID and independently evaluate authority before mutation. Controllers pass the authenticated user's ID, not request payload flags. Form Requests retain their unchanged early Policy authorization, preserving denial-before-validation. Re-evaluation at the write boundary is intentional: there is one implementation of the rule, consulted by two entry boundaries.

SaveRole's cross-organization role guard remains a resource-scope rejection independent of actor authorization. It now raises a HIDDEN Application denial instead of abort_if; scoped HTTP binding remains the first nested-resource boundary. Its transaction, row lock, permission replacement, duplicate conflict handling and rollback are unchanged. The locked query still scopes the persisted role to its parent even if a caller spoofs a role object's organization attribute.

CreateOrganization, AssignMembershipRole and ListOrganizations are unchanged. Creation still receives the explicit owner and creates membership transactionally; assignment remains internal and protects the same tenant invariant; listing remains one explicitly actor-scoped query without per-row access decisions. RBAC read queries were not extracted. Domain invariant extraction remains deferred.

SaveRole and AssignMembershipRole retain their narrow ValidationException dependencies for existing non-authorization errors. Application has no abort/abort_if authorization calls. Architecture rules remove the SaveRole exception and forbid Gate, access contracts and the Infrastructure authorization adapters, in addition to existing HTTP/ambient-helper bans. Same-module Eloquent/DB and the existing CreateOrganization→User exception remain intentional. Domain stays framework-independent.

Authentication and email verification remain Identity/HTTP middleware responsibilities; the evaluator decides Organization capabilities for a trusted explicit identity, not login/session validity. Application callers must still validate names/permissions and supply trusted IDs/models. This is not a redesign of input validation. Authorization reads are fresh per call, but this checkpoint does not serialize concurrent revocation after a decision or change transaction isolation. Existing concurrency semantics/locks remain in place.

## Files created and modified

Created under `backend/`:

- app/Modules/Organization/Application/Authorization/OrganizationAccess.php
- app/Modules/Organization/Application/Authorization/AccessDecision.php
- app/Modules/Organization/Application/Authorization/AccessDenied.php
- app/Modules/Organization/Infrastructure/Authorization/AccessResponse.php
- tests/Feature/Application/OrganizationAccessTest.php
- tests/Feature/Application/OrganizationWriteAuthorizationTest.php

Modified: RenameOrganization, SaveRole, OrganizationPolicy, OrganizationMembership, both Organization HTTP controllers, bootstrap/app.php, tests/Architecture/BoundariesTest.php, tests/Feature/Application/OrganizationOperationsTest.php and tests/Feature/RbacTest.php. README's status summary, system overview, roadmap and this record reflect D. ADR 0005 remains unchanged because this implements its approved authorization direction. No provider, route, Form Request, Resource, migration, seed, dependency, Identity, frontend or permission-catalog changes.

## Security test evidence

Added 19 PostgreSQL-backed cases across two files:

- Evaluator covers all four abilities for owner, member and outsider, missing organization, no owner roles, multi-role unions, role/permission/member revocation, stale relationships and spoofed in-memory ownership/membership data.
- Policy parity covers all abilities and allowed/hidden/forbidden decisions and stable messages.
- Separate stale-ownership test changes persisted ownership through a separate model and verifies both evaluator and Policy see the new owner. Removing that owner's membership transiently inside the existing rollback transaction hides all abilities; the deferred FK would prevent committing that state.
- Direct rename matrix covers owner/editor success, ordinary member forbidden and non-member hidden, despite an ambient owner login. Additional cases cover immediate revocation, spoofed objects, and permissions/ownership elsewhere.
- Direct role-write matrix tests both create and update: only owner succeeds; ordinary member, both-permission holder (even with a role named Owner), and owner of another organization are denied. Failed writes preserve names, grants, role counts and ownership.
- Direct foreign-role writes are hidden; spoofed in-memory tenant attributes cannot evade the persisted scoped lookup.
- Four test-only HTTP routes exercise real Application denial translation without a Policy/Form Request. They verify exact 403/404 messages and no mutation, without mocking OrganizationAccess. No test routes were added to production routing.

Existing RBAC assertions now target explicit AccessDecision outcomes; a redundant cross-tenant permission assertion was replaced by checking that membership still grants view while denying update. Existing HTTP, resource, nested-binding, database constraint, duplicate-race and rollback assertions remain. Tests use real persisted PostgreSQL state; no evaluator mocks or backend parallelism.

Incremental results: baseline security/Application/Architecture **45 passed / 558 assertions**; evaluator first **1 / 24**; Policy conversion plus existing feature/characterization **32 / 404**; rename protection plus full targeted HTTP set **44 / 447**; role protection/Architecture **63 / 693**. A final ownership edge case brought the full suite to the result below. A test assertion readability refinement was followed by RBAC **12 / 113** and Pint passing.

During incremental validation, Laravel rejected an array callable for exception mapping. Replaced it with the supported first-class Closure (`AccessResponse::exception(...)`). The targeted tests then passed; no framework override or unrelated exception change was needed.

## Final validation

| Check | Result |
| --- | --- |
| Complete backend quality / Pest | **84 passed, 796 assertions**, no skips |
| Architecture separately | **9 passed, 189 assertions**, no skips |
| Pint | Passed, 74 files |
| Larastan/PHPStan | Level 8, 46 files, no errors |
| Composer strict validate / platform checks | Passed |
| Composer audit | No security vulnerability advisories |
| Frontend quality | ESLint, Prettier, vue-tsc, **28 Vitest tests / 7 files**, production build passed |
| npm audit | 0 vulnerabilities |
| Complete pinned Playwright | **3 passed**, existing two browser workers |
| Infrastructure | Compose valid; all five services healthy; PostgreSQL accepting, Redis PONG, Mailpit HTTP 200, health/API/proxy status ok |
| Routes | Before/after API route JSON byte-identical (cmp), all 11 entries |
| Migrations | All three historical migrations Ran; no migration executed |
| Git | diff --check passed; migration/frontend diffs empty |

## Commands executed

Read-only inspection used cat/rg over guidance, ADR, full checkpoint history, module classes, tests and Laravel's installed exception implementation; Git branch/status/log/show/diff checks. Substantive validation commands (some repeated at incremental boundaries):

```sh
docker compose exec -T backend php artisan route:list --path=api --json
docker compose exec -T backend php vendor/bin/pest tests/Feature/OrganizationsTest.php tests/Feature/RbacTest.php tests/Feature/ArchitectureCharacterizationTest.php tests/Feature/Application tests/Architecture --compact
docker compose exec -T backend php vendor/bin/pest tests/Feature/Application/OrganizationAccessTest.php --compact
docker compose exec -T backend php vendor/bin/pest tests/Feature/Application/OrganizationAccessTest.php tests/Feature/OrganizationsTest.php tests/Feature/RbacTest.php tests/Feature/ArchitectureCharacterizationTest.php --compact
docker compose exec -T backend php vendor/bin/pest tests/Feature/Application tests/Feature/OrganizationsTest.php tests/Feature/RbacTest.php tests/Feature/ArchitectureCharacterizationTest.php --compact
docker compose exec -T backend php vendor/bin/pest tests/Feature/Application tests/Feature/OrganizationsTest.php tests/Feature/RbacTest.php tests/Feature/ArchitectureCharacterizationTest.php tests/Architecture --compact
docker compose exec -T backend php vendor/bin/pint app/Modules/Organization/Application/Authorization app/Modules/Organization/Application/Commands/RenameOrganization.php app/Modules/Organization/Application/Commands/SaveRole.php app/Modules/Organization/Infrastructure/Authorization app/Modules/Organization/Infrastructure/Eloquent/Models/OrganizationMembership.php app/Modules/Organization/Presentation/Http/Controllers bootstrap/app.php tests/Architecture/BoundariesTest.php tests/Feature/Application tests/Feature/RbacTest.php
docker compose exec -T backend composer quality
docker compose exec -T backend php vendor/bin/pest --testsuite=Architecture --compact
docker compose exec -T backend php vendor/bin/pest tests/Feature/RbacTest.php --compact
docker compose exec -T backend php vendor/bin/pint --test tests/Feature/RbacTest.php
docker compose exec -T backend composer validate --strict
docker compose exec -T backend composer check-platform-reqs
docker compose exec -T backend composer audit
docker compose exec -T frontend npm run quality
docker compose exec -T frontend npm audit
docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test
cmp /tmp/coreerp-d-routes-before.json /tmp/coreerp-d-routes-after.json
docker compose exec -T backend php artisan migrate:status
docker compose config --quiet
docker compose ps
docker compose exec -T postgres pg_isready -U coreerp
docker compose exec -T redis redis-cli ping
curl --fail --silent --show-error http://localhost:8088/api/v1/health
curl --fail --silent --show-error http://localhost:8088/api/v1/ready
curl --fail --silent --show-error http://localhost:5174/api/v1/ready
curl --fail --silent --show-error -o /dev/null -w 'Mailpit HTTP %{http_code}\n' http://localhost:8026/
git diff --check
git diff -- backend/database/migrations
git diff -- frontend
git status --short
git diff --stat
```

## Compatibility, limitations and review

Guest 401, unverified 403, hidden non-member 404, unauthorized member 403, foreign nested role 404-before-validation, resource envelopes/statuses/ordering and meta.can_manage remain covered and unchanged. Missing-model namespace wording remains as recorded in C; this checkpoint does not normalize 404s. Direct write invocation intentionally now requires actor authorization. Database schema/history, frontend, routes, middleware, catalog, Form Requests and Resources are unchanged. Browser fixture data and ignored build/cache output are normal test artifacts; production/hosted CI were not exercised.

Review the trusted actor inputs, persisted ownership/membership queries, resource-scope vs actor-access distinction in SaveRole, the exception adapter/registration, and direct-denial tests. Static guards are not complete data-flow/security proofs. Existing cross-origin PATCH/CORS and test-database isolation follow-ups remain deferred. The final plain diff stat excludes six untracked files; inspect those files too.

Recommend a separately approved small checkpoint for pure membership-role tenant-invariant extraction and its error translation, while retaining the security tests established here. Identity migration should remain independently reviewable. **D ends here; no next checkpoint or Phase 1.4 work has begun.**

# Phase 1.3.5E — Domain Invariants and Application Error Cleanup

- Date: 2026-10-01
- Status: E complete against local validation. Identity migration and Phase 1.4 have not started; overall 1.3.5 remains in progress.
- Baseline: clean `refactor/ddd-architecture`, `5821956` (`refactor: centralize organization authorization`). No staging, commits, pushes, branch switches, resets or discarded work.

## Domain invariant and persistence boundary

The first pure Domain rule is `Domain/Memberships/RoleAssignmentRules::requireSameOrganization(string $membershipOrganizationId, string $roleOrganizationId)`. It requires exact equality and throws the specific PHP DomainException subclass CrossOrganizationRoleAssignment on mismatch. Both classes are independent of Laravel, Eloquent, HTTP and Application. The rule checks compatibility, not identifier syntax or persisted existence; actual organization identifiers remain ULID strings.

AssignMembershipRole remains internal. Previously it compared supplied Eloquent attributes and constructed ValidationException; now it passes those scalar organization IDs to the Domain rule and performs the same syncWithoutDetaching operation. Inspection confirmed it did not previously load/reload either record: callers provide the loaded models. That contract, explicit pivot organization_id, idempotence, and lack of actor authorization remain unchanged. No new lookup, transaction, or membership-management endpoint was added.

The Domain rule gives an early business failure, while the PostgreSQL composite foreign keys independently validate persisted tenant identity. A dirty model attribute may make scalar inputs appear compatible; it cannot bypass the existing database constraints. Tests retain raw SQL corruption checks and add a spoofed-model assignment check. No constraints or historical migrations changed.

## Error classification and cleanup

| Existing case | Classification and implementation |
| --- | --- |
| Cross-organization membership/role | Pure Domain invariant; CrossOrganizationRoleAssignment replaces Application ValidationException |
| Role-name uniqueness race/conflict | Application persistence conflict; RoleNameConflict replaces ValidationException |
| Malformed/unknown HTTP permission keys | Presentation validation remains in SaveRoleRequest; unchanged Rule::enum and field validation |
| Invalid permission input from direct PHP caller | Native PermissionKey argument typing rejects the call before mutation; no HTTP validation dependency |
| Unrelated database failures | Existing QueryException propagation remains; only SQLSTATE 23505 for roles_organization_name_unique becomes RoleNameConflict |

SaveRole now takes `PermissionKey ...$permissions`. Callers pass enum cases or unpack a list of cases; no permission arguments clears grants. SaveRoleRequest::permissionKeys converts the already-validated string list, and the two controller calls unpack it. Clients still send the same array of strings. PHP rejects raw strings (including known-but-unconverted strings), arrays and null with TypeError before entering the use case. Undefined enum cases cannot be constructed. The database catalog allowlist and permission foreign key remain independent protection against raw corruption. No DTO or redundant Application string validator was introduced.

SaveRole still owns authorization through the unchanged OrganizationAccess, role scoping, transaction, row lock, name update, permission replacement and rollback. It maps enum values to database keys at synchronization. The existing narrowly identified duplicate database error becomes RoleNameConflict after transaction rollback; the previous QueryException is retained for diagnostics. The exception has no HTTP status or JSON structure. No transaction/retry/concurrency redesign.

Presentation/Http/Exceptions/OrganizationFailureMapper maps RoleNameConflict to the existing Laravel ValidationException name-field response, and CrossOrganizationRoleAssignment to its existing role-field response. bootstrap/app.php registers these two exact exception mappings. Domain/Application exceptions contain business messages only. The HTTP statuses (422), errors fields, envelopes and text remain unchanged. Assignment still has no production HTTP endpoint; a test-only route proves the adapter representation if the failure reaches HTTP.

All ValidationException dependencies were removed from Organization Application. Architecture tests remove both former exceptions and now reject ValidationException for the entire Application layer. Domain-to-Application/Infrastructure/Presentation and framework dependencies remain forbidden. Application intentionally retains same-module Eloquent, DB transactions, QueryException, collection types, and the existing CreateOrganization→User dependency allowed by ADR 0005. Persistence-level model lookup errors retain Laravel's established rendering; no new HTTP exceptions or abort helpers were added.

## Files changed

Six new files under backend:

- app/Modules/Organization/Domain/Memberships/RoleAssignmentRules.php
- app/Modules/Organization/Domain/Memberships/CrossOrganizationRoleAssignment.php
- app/Modules/Organization/Application/Exceptions/RoleNameConflict.php
- app/Modules/Organization/Presentation/Http/Exceptions/OrganizationFailureMapper.php
- tests/Unit/Organization/RoleAssignmentRulesTest.php
- tests/Feature/Application/OrganizationFailuresTest.php

Modified production/configuration: SaveRole, AssignMembershipRole, OrganizationRoleController (input adaptation only), SaveRoleRequest (conversion method only), bootstrap/app.php (specific error mappings), phpunit.xml (Unit suite discovery). Tests updated: Architecture/BoundariesTest, Feature/RbacTest, Application/OrganizationOperationsTest, Application/OrganizationWriteAuthorizationTest. README status, system overview, roadmap and this validation record updated. ADR 0005 and AGENTS.md unchanged.

OrganizationAccess, AccessDecision, AccessDenied, AccessResponse, Policies, Eloquent models, CreateOrganization, RenameOrganization, ListOrganizations, routes, Resources, middleware, providers, Identity/Fortify, dependencies and frontend remain unchanged.

## Test evidence and incremental results

- Baseline Organization/RBAC/Application/characterization/Architecture: **64 passed / 701 assertions**.
- Domain/assignment extraction with Unit, RBAC and Architecture: **26 / 311**.
- Typed permissions/error cleanup with Unit/RBAC/Application/characterization/Architecture: **61 / 657**.
- New failure tests plus RBAC and Unit: **27 / 168**.

Five pure Unit cases cover equal identifiers (including ULID boundaries) and different identifiers with the exact Domain failure. tests/Unit uses plain Pest/PHPUnit; it does not extend the Laravel TestCase, boot the application, or access PostgreSQL. phpunit.xml includes it in normal backend quality.

Ten new PostgreSQL-backed failure cases cover idempotent local assignment, Domain rejection without a pivot, database rejection despite spoofed attributes, direct duplicate-name create/update conflicts with no partial mutation, four invalid PHP permission input types, exact HTTP conflict/domain error translation, and exact unknown-key HTTP validation output. Existing success representations, sorting, clearing grants, scoped foreign roles, authorization and PostgreSQL corruption checks continue to run.

The old rollback test used an unknown string to provoke a permission FK failure after updating a role name. Native enum typing now rejects that input before the transaction, so the rollback test instead injects an exception after a real role_permission insert using a test-local query listener. It verifies that the fault was reached and the role name and prior grants were rolled back together. The database is real and transaction behavior is not mocked. Existing raw SQL tests still independently reject unknown permissions and cross-tenant pivots.

An initial full-quality run passed tests/Pint but Larastan could not prove the conversion returned a list. Adding array_values made the return shape explicit; the validated HTTP list and order are unchanged. No suppression or analysis-level reduction. Following session resumption, processes/temp artifacts were unavailable and Compose services stopped; the existing stack was restarted without rebuilding or migrating, then final backend validation completed.

## Final results

| Check | Result |
| --- | --- |
| Full backend quality / Pest | **99 passed, 855 assertions**, no skips |
| Pure Unit suite separately | **5 passed, 7 assertions**, no Laravel/database |
| Architecture separately | **9 passed, 193 assertions**, no skips |
| Pint | Passed, 80 files |
| Larastan/PHPStan | Level 8, 50 files, no errors |
| Composer strict validation / platform check | Passed |
| Composer audit | No security vulnerability advisories |
| Frontend quality | ESLint, Prettier, vue-tsc, 28 Vitest tests / 7 files, production build passed |
| npm audit | 0 vulnerabilities |
| Complete pinned Playwright | 3 passed |
| Infrastructure | Compose valid, five healthy services, PostgreSQL accepting connections, Redis PONG, Mailpit HTTP 200, API health/readiness and proxy status ok |
| Migration status | All three historical migrations Ran; none executed |
| Routes | Inspected before/after; same 11 API endpoints, route source unchanged |
| Git | diff --check clean; migration/frontend diffs empty |

Backend database tests remained sequential and completed before browser writes. The pre-change route JSON was a temporary artifact lost across resumption, so no final byte-comparison of that artifact is claimed; route source and registration were unchanged and the final route table/security tests passed. Local checks do not establish hosted CI or production deployment results.

## Commands executed

Inspection used cat/rg over guidance, ADR, checkpoint records, module classes/tests/migrations and Git branch/status/log/diff checks. Relevant validation:

```sh
docker compose exec -T backend php artisan route:list --path=api --json
docker compose exec -T backend php vendor/bin/pest tests/Feature/OrganizationsTest.php tests/Feature/RbacTest.php tests/Feature/Application tests/Feature/ArchitectureCharacterizationTest.php tests/Architecture --compact
docker compose exec -T backend php vendor/bin/pest tests/Unit tests/Feature/RbacTest.php tests/Architecture --compact
docker compose exec -T backend php vendor/bin/pest tests/Unit tests/Feature/RbacTest.php tests/Feature/Application tests/Feature/ArchitectureCharacterizationTest.php tests/Architecture --compact
docker compose exec -T backend php vendor/bin/pest tests/Feature/Application/OrganizationFailuresTest.php tests/Feature/RbacTest.php tests/Unit --compact
docker compose exec -T backend php vendor/bin/pint app/Modules/Organization/Domain/Memberships app/Modules/Organization/Application/Exceptions app/Modules/Organization/Application/Commands/SaveRole.php app/Modules/Organization/Application/Operations/AssignMembershipRole.php app/Modules/Organization/Presentation/Http/Exceptions app/Modules/Organization/Presentation/Http/Controllers/OrganizationRoleController.php app/Modules/Organization/Presentation/Http/Requests/SaveRoleRequest.php bootstrap/app.php tests/Unit tests/Architecture/BoundariesTest.php tests/Feature/RbacTest.php tests/Feature/Application/OrganizationFailuresTest.php tests/Feature/Application/OrganizationOperationsTest.php tests/Feature/Application/OrganizationWriteAuthorizationTest.php
docker compose exec -T backend composer quality
docker compose exec -T backend php vendor/bin/pest --testsuite=Unit --compact
docker compose exec -T backend php vendor/bin/pest --testsuite=Architecture --compact
docker compose exec -T backend composer validate --strict
docker compose exec -T backend composer check-platform-reqs
docker compose exec -T backend composer audit
docker compose exec -T frontend npm run quality
docker compose exec -T frontend npm audit
docker compose up -d --wait
docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test
docker compose config --quiet
docker compose ps
docker compose exec -T postgres pg_isready -U coreerp
docker compose exec -T redis redis-cli ping
curl --fail --silent --show-error http://localhost:8088/api/v1/health
curl --fail --silent --show-error http://localhost:8088/api/v1/ready
curl --fail --silent --show-error http://localhost:5174/api/v1/ready
curl --fail --silent --show-error -o /dev/null -w 'Mailpit HTTP %{http_code}\n' http://localhost:8026/
docker compose exec -T backend php artisan migrate:status
git diff --check
git diff -- backend/database/migrations
git diff -- frontend
git status --short
git diff --stat
```

## Compatibility, review and stop point

Existing HTTP payloads, 201/200 resources, 422 fields/messages, 401/403/404 security semantics, grant ordering and meta.can_manage remain unchanged. Internal callers intentionally use typed permission arguments and catch specific Domain/Application failures instead of Laravel ValidationException. No database schema, historical migrations, catalog seed data or frontend changes; no new production endpoints. Normal browser fixtures and ignored build/cache outputs are test artifacts, not claims of byte-identical database contents. The earlier CORS/deployment and database-test isolation follow-ups remain deferred.

Review the scalar rule's responsibility versus persisted composite constraints, the variadic enum signature/input conversion, exact conflict detection and mapping, and the revised rollback fault injection. Plain git diff --stat excludes six new untracked files; inspect them too.

Recommend the next separately approved checkpoint be a mechanical Identity migration: preserve User relationships, auth configuration, factory/model resolution, Fortify bindings, current-user representations, notifications and authentication URLs; keep it separate from any behavioral cleanup or Phase 1.4 functionality. **Stop after E; Identity migration has not begun.**

## Phase 1.3.5F — Mechanical Identity Module Migration

Status: complete locally; awaiting review/commit. Only F was implemented. The final cleanup/enforcement checkpoint and Phase 1.4 have not started. ADR 0005 remains unchanged.

### Baseline and inspection

Started on `refactor/ddd-architecture`, clean working tree, E checkpoint `d8cfdb5` (`refactor: extract organization domain invariants`). Read AGENTS.md, ADR 0005 and the preceding validation record; inspected auth/Fortify/Sanctum configuration, provider registration, routes, model/factory, actions/responses/resources, auth tests and Organization consumers. Repository-wide reference searches found no use of `User::organizationMemberships()` outside its declaration and no application-class dependencies in historical migrations. Baseline authentication: 13 tests / 71 assertions; complete backend: 99 tests / 855 assertions.

### Changes and ownership

Eight existing classes moved into `App\Modules\Identity`; the only new production class is the trivial CurrentUserController:

```text
backend/app/Modules/Identity/
├── Infrastructure/
│   ├── Eloquent/Models/User.php
│   ├── Fortify/
│   │   ├── CreateNewUser.php
│   │   ├── PasswordRules.php
│   │   └── ResetUserPassword.php
│   └── Providers/FortifyServiceProvider.php
└── Presentation/Http/
    ├── Controllers/CurrentUserController.php
    ├── Resources/UserResource.php
    └── Responses/
        ├── LoginResponse.php
        └── PasswordResetLinkResponse.php
```

- User remains Eloquent/Authenticatable/MustVerifyEmail with unchanged casts, fillable/hidden fields, hashing and notifications. Its unused inverse Organization relationship was removed as explicitly authorized. No Identity Domain/Application directories, aggregate, repository, events or Shared Kernel were introduced.
- `config/auth.php` changes only the User import. The existing AUTH_MODEL override, guards and brokers remain intact. `bootstrap/providers.php` registers the moved Fortify provider in the same order.
- User explicitly declares `UseFactory(UserFactory::class)`; the central UserFactory explicitly sets `$model = User::class`. No factory/seed structure changes; the empty seeder needs no edit.
- Fortify actions/provider, UserResource and both response adapters have equivalent class bodies after excluding namespace/import/formatting differences (compared to HEAD). Password validation/normalization, throttling, reset URLs, login 204 and reset-link response parity remain unchanged.
- `/api/v1/me` now invokes CurrentUserController, using the same request-user-to-UserResource expression. It retains Sanctum middleware, guest 401, unverified-user access and the same four resource fields.
- Six Organization files change only their User import: Organization and OrganizationMembership models, OrganizationPolicy, CreateOrganization, OrganizationController and OrganizationRoleController. AccessDecision, OrganizationAccess, OrganizationFailureMapper and authorization/transaction behavior are unchanged.
- Ten existing feature-test files update imports (four Application tests, characterization, three Auth tests, OrganizationsTest, RbacTest). BoundariesTest gains three architecture rules and updates its existing User exception. Characterization adds a persisted auth-provider retrieval and both Organization-to-User relationship checks. Existing factory, policy, Fortify, auth, reset, verification, tenancy and API assertions are retained.
- README, system overview, Phase 1 roadmap and this validation record describe the implemented checkpoint. AGENTS.md, ADRs, Composer/PHPStan configuration, dependency locks, migrations and frontend source are unchanged.

### Remaining cross-context dependencies and safeguards

Identity has no Organization dependency. Organization owns membership and tenant RBAC. Organization's two persistence relationships reference the moved Identity User. Four additional pre-existing actor adapters/signatures also reference User: OrganizationPolicy, CreateOrganization (reads the explicit owner's key only), and the two HTTP controllers. They are retained mechanically, not represented as newly approved general dependencies or Identity mutations. The architecture suite allows exactly these six consumers of User, rejects other Identity internals, and rejects all Identity-to-Organization references. Existing Domain isolation and Application-to-HTTP restrictions remain active. Reducing the CreateOrganization actor type to an identifier is a potential separately authorized cleanup decision, not part of F.

### Commands and results

Commands ran from the repository root; backend tests remained sequential, with browser writes only after backend tests finished.

| Command | Result |
| --- | --- |
| `git branch --show-current`, `git status --short`, `git log -1 --oneline` | Correct branch, clean E baseline |
| `docker compose exec -T backend php vendor/bin/pest tests/Feature/Auth --compact` | Baseline and final: 13 passed, 71 assertions; includes reset and verification |
| `docker compose exec -T backend composer test` | Baseline: 99 passed, 855 assertions |
| `docker compose exec -T backend php vendor/bin/pest tests/Feature/Auth tests/Feature/ArchitectureCharacterizationTest.php tests/Feature/OrganizationsTest.php tests/Feature/RbacTest.php --compact` | After User/factory/relationship move: 43 passed, 432 assertions |
| `docker compose exec -T backend php vendor/bin/pest tests/Feature/Auth/AuthenticationTest.php tests/Feature/Auth/PasswordResetTest.php --compact` | After Fortify action move: 10 passed, 55 assertions |
| `docker compose exec -T backend php vendor/bin/pest tests/Feature/Auth --compact` | After provider move: 13 passed, 71 assertions |
| `docker compose exec -T backend php vendor/bin/pest tests/Feature/ArchitectureCharacterizationTest.php tests/Architecture --compact` | Final: 23 passed, 407 assertions |
| `docker compose exec -T backend composer dump-autoload --optimize --strict-psr` | Passed; package discovery and 9,052-class optimized autoload generated |
| `docker compose exec -T backend php vendor/bin/pint <changed PHP paths>` | Applied import ordering only; paths enumerated using host Git |
| `docker compose exec -T backend composer quality` | 103 tests / 880 assertions; Pint 81 files; Larastan level 8, no errors (51 analyzed files) |
| `docker compose exec -T backend php vendor/bin/pest --testsuite=Architecture --compact` | 12 passed, 212 assertions, no skips |
| `docker compose exec -T backend composer validate --strict` | Valid |
| `docker compose exec -T backend composer check-platform-reqs` | All requirements passed |
| `docker compose exec -T backend composer audit` | No vulnerability advisories |
| `docker compose exec -T frontend npm run quality` | ESLint, Prettier, TypeScript, 7 Vitest files / 28 tests, production build passed |
| `docker compose exec -T frontend npm audit` | Zero vulnerabilities |
| `docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test` | All 3 passed, none skipped; existing 2 browser workers unchanged |
| `docker compose config --quiet`, `docker compose ps` | Valid; backend, frontend, PostgreSQL, Redis, Mailpit healthy |
| `docker compose exec -T postgres pg_isready -U coreerp` | Accepting connections |
| `docker compose exec -T redis redis-cli ping` | PONG |
| `curl --fail --silent --show-error http://localhost:8088/api/v1/health` | HTTP 200, data.status ok |
| `curl --fail --silent --show-error http://localhost:8088/api/v1/ready` | HTTP 200, data.status ok |
| `curl --fail --silent --show-error http://localhost:5174/api/v1/ready` | HTTP 200 through frontend proxy, data.status ok |
| `curl --fail --silent --show-error -o /dev/null -w 'Mailpit HTTP %{http_code}\n' http://localhost:8026/` | HTTP 200 |
| `docker compose exec -T backend php artisan route:list --json` | Before/after: 21 routes; only /me action and closure source-location metadata differ |
| `docker compose exec -T backend php artisan migrate:status` | All three historical migrations Ran |
| `git diff --check` | Passed |
| `git diff -- backend/database/migrations`, `git diff -- frontend` | Both empty |
| `git diff --find-renames`, `git status --short`, `git diff --stat` | Reviewed; unstaged new module remains untracked, so ordinary diff does not include its additions |

Initial validation corrections: the new provider test first omitted the provider name, which can resolve null when the default guard has been switched to Sanctum by earlier tests; it now explicitly requests the configured `users` provider. No production fix was needed. Pint's `--dirty` option was unavailable inside the container (no Git metadata); host-enumerated changed PHP paths were formatted instead. An initial quality run passed all tests but found four import-order issues, then the complete quality rerun passed. Route comparison initially included closure source-location metadata; inspection established the expected action/path-only change before comparing HTTP contract fields. No failing check remains.

### Compatibility, limitations and review points

- Authentication, password recovery/verification, Organization/RBAC, session/security, API resource/status and tenant semantics have no intentional changes; backend and browser suites pass. Redis-backed refresh/logout, CSRF and Mailpit verification were exercised by the existing browser flows.
- No migration/schema or frontend source changes. Tests/browser workflows create ordinary test records as before; this is not a schema/data migration. The same three migration files remain applied.
- Framework class identity necessarily changes with the namespace; `/me` route-list action/source metadata changes as described. No additional externally visible HTTP behavior change was observed. Existing incidental Organization missing-model 404 text remains as recorded in C; no response normalization was introduced.
- Current development runtime has no AUTH_MODEL override referencing the old class. External deployments with such an override or stale config/autoload caches must update/rebuild them when deploying; no compatibility alias was introduced. No cross-version serialized payload deployment was tested.
- Existing PostgreSQL outer-transaction/schema-manipulation test limitations remain. Backend tests were not parallelized. Existing CORS cross-origin PATCH concern is unchanged and deferred; the supported same-origin proxy passes.
- Review factory wiring, explicit provider registration, removal of the unused inverse relationship, the /me adapter, and the six exact cross-context exceptions. Static dependency rules are not a proof of absence of indirect Identity writes or tenant leaks.
- No staging, commit, push, branch switch, reset or discarded work. Stop after F. Recommend reviewing/committing this checkpoint before separately authorizing the final cleanup/enforcement inventory; do not introduce additional abstractions merely for symmetry.
