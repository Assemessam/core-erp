# Phase 1.3 — Organization-scoped RBAC validation

Date: 2026-09-30. Local validation on `feature/rbac`, based on `b4f0c8a` (`feat: add organizations and tenant membership foundation`). The starting working tree was clean. No commit, push, branch change, reset, discard, or staging operation was performed.

## Before editing

Read root AGENTS.md, README, ADRs 0001–0003, the system overview, Phase 1 roadmap, and all three earlier validation records. Inspected organization models, policy, transactional creation, migration, routes, resources, requests, frontend state/routing, authentication conventions, test configuration, and CI commands.

Baseline backend quality passed 28 Pest tests / 152 assertions, Pint and Larastan. Baseline frontend quality passed 19 Vitest tests, ESLint, Prettier, TypeScript and build. The two existing Playwright flows passed in the pinned browser image before implementation.

## Implemented architecture and migration

See [ADR 0004](../decisions/0004-organization-scoped-rbac.md) for the complete decision. `User → OrganizationMembership → Role(s) → Permission` scopes authority to one organization. Ownership remains explicit and independently queryable through `organizations.owner_user_id`. The membership permission method grants owners authority without role assignments and otherwise evaluates the union of current role grants. Existing membership-based workspace viewing remains intact. Organization updates now accept the owner or a member with `organizations.update`; roles/catalog reads accept the owner or `roles.view`; role writes remain owner-only.

`2026_09_30_000002_create_rbac_tables.php` adds:

- `roles`: organization FK, ULID identity, 80-character name and timestamps; scoped case-insensitive trimmed-name uniqueness and nonempty check.
- `permissions`: stable application keys, populated by the migration and constrained to the PHP enum's current catalog.
- `role_permission`: unique role/permission pairs with FK integrity.
- `organization_membership_role`: unique membership/role pairs with two composite FKs sharing the organization key.
- Supporting composite unique keys/indexes for tenant FK references.

The migration applied successfully as batch 3. A transactional PostgreSQL test rolls RBAC back, creates a Phase 1.2-style organization, reapplies RBAC, and proves ownership and authority survive without default roles. The test's outer transaction restores pre-test data, including any existing development roles. The Phase 1.2 migration and owner-membership invariant are unchanged. There is no ownership/membership backfill and no manual organization recreation.

Four authenticated, verified APIs were added under `/api/v1/organizations/{organization}`: `GET roles`, `POST roles`, `PATCH roles/{role}`, and `GET permissions`. Both mutations require a name and complete permission-key list. Role resources contain only ID, name and sorted permission keys; permission resources contain key and label. The roles list also returns `meta.can_manage` for UI presentation. No member-assignment endpoint exists.

The organization workspace links to Roles & Permissions. Owners can list/create/edit names and grants, readers get a read-only catalog, and denied users see an error with no form. The view retains route organization context, uses local state, and ignores stale responses after tenant navigation.

## Final results

| Check | Observed result |
| --- | --- |
| Complete Pest suite | 39 passed, 256 assertions, including real PostgreSQL/Redis integration |
| Pint | Passed, 62 PHP files |
| Larastan/PHPStan | Level 8 passed; 38 analyzed application files; no errors or baseline |
| Complete Vitest suite | 28 passed across 7 files |
| ESLint / Prettier / TypeScript | All passed |
| Production frontend build | Passed; 116 modules transformed |
| Composer strict validation / platform requirements | Passed |
| Composer audit | No security vulnerability advisories |
| npm audit at low threshold | 0 vulnerabilities |
| Complete Playwright suite | 3 passed: authentication; organization onboarding/isolation; new RBAC create/edit/refresh flow |
| Compose configuration/startup | Valid; all five services healthy |
| PostgreSQL / Redis | `pg_isready` accepted connections; `redis-cli ping` returned PONG |
| Backend liveness/readiness and Vite readiness proxy | All returned `{"data":{"status":"ok"}}` |
| Mailpit API | Responded successfully, v1.27.1 |
| Migration status / API route inspection | All three migrations Ran; 11 API routes, including the four RBAC endpoints |
| `git diff --check` | Passed |

## Security verification

Integration tests use the actual permission evaluator and PostgreSQL, without mocks of the central authorization behavior. They prove:

- Lists are tenant-scoped, including for an owner of two organizations; a foreign role ID is rejected even when the actor owns both organizations.
- Non-members receive 404 on role endpoints, while members lacking the relevant authority receive 403. Guests receive 401 and unverified users 403. Invalid payloads do not bypass the policy or reveal validation details to unrelated actors.
- Members can hold multiple roles, receive the union, and lose a permission on the next check after grants are removed. Loading relationships beforehand does not preserve revoked authority.
- A permission in one membership grants nothing in another organization, even for the same user. Spoofing in-memory membership user/tenant fields cannot fool the evaluator.
- Cross-tenant assignment fails in the internal action and through raw SQL using either tenant ID. Moving referenced tenant keys is also constrained.
- Role-name, role-permission and membership-role duplicates fail at the database; role and membership references require real rows. Permission keys are application-defined at both request and database boundaries.
- Ownership survives migration and needs no Owner role. A regular role named Owner carries no implicit authority or ownership. Organization updates cannot change the owner.
- Role readers/editors cannot create or modify roles; only the explicit owner can bootstrap/manage RBAC. No global roles, user permission fields, or system administrators were introduced.
- Mutations are atomic, including permission-write failure rollback and database duplicate-name race handling. Only explicit validated fields reach persistence, and the Role model only permits mass assignment of `name`.
- Cascades remove dependent links when the referenced role/membership/organization is deleted; referenced permission deletion is restricted. No public deletion workflow was introduced.
- Resources omit organization internals, timestamps and pivot fields. Scoped route binding and Policies remain authoritative independently of Vue controls.

Frontend tests cover loading/listing, create/edit permission sets, validation failure/input retention, 403/404/server failures, read-only display, server denial after controls were initially visible, route links, and stale response rejection. Playwright registers and verifies a real owner through Mailpit, creates an organization, creates/edits a role with both capabilities, refreshes, verifies saved grants, and returns to the original workspace. Deeper second-membership semantics remain backend integration coverage; no Phase 1.4 workflow was added for a browser fixture.

Intermediate failures were resolved: eager loading exposed that a relationship cannot require an instance pivot value while Eloquent constructs an empty model; pivot values now belong to the assignment action, with tenant integrity enforced by the database. Larastan required a typed permission projection in the role resource. Final quality runs pass without suppressions.

## Commands executed

Inspection used `pwd`, `rg --files`, `cat`, `head`, `tail`, `git branch --show-current`, `git status --short`, `git log -1 --oneline`, and targeted `git diff` reads. Files were authored with shell heredocs/Python and formatted using the repository formatters. The substantive validation commands below ran from the repository root (quality commands were also used for baseline and iterative validation):

```sh
docker compose exec -T backend composer quality
docker compose exec -T frontend npm run quality
docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test
docker compose exec -T backend php artisan migrate --no-interaction
docker compose exec -T backend composer format
docker compose exec -T frontend npm run format
docker compose exec -T backend composer validate --strict
docker compose exec -T backend composer check-platform-reqs
docker compose exec -T backend composer audit
docker compose exec -T frontend npm audit --audit-level=low
docker compose config --quiet
docker compose up -d --wait
docker compose ps
docker compose exec -T postgres pg_isready -U coreerp
docker compose exec -T redis redis-cli ping
curl --fail --silent --show-error http://localhost:8088/api/v1/health
curl --fail --silent --show-error http://localhost:8088/api/v1/ready
curl --fail --silent --show-error http://localhost:5174/api/v1/ready
curl --fail --silent --show-error http://localhost:8026/api/v1/info
docker compose exec -T backend php artisan migrate:status
docker compose exec -T backend php artisan route:list --path=api
git diff --check
git status --short
git diff --stat
```

Backend `composer quality` executes the complete Pest suite, Pint check and PHPStan. Frontend `npm run quality` executes ESLint, Prettier check, TypeScript, Vitest and production build. No dependency manifests or lockfiles changed.

## Review boundaries and limitations

Manually review the new migration's composite foreign keys and name index, the membership evaluator/OrganizationPolicy, scoped role binding, request/action validation and transactional writes, and ADR 0004's owner-only bootstrap decision. The exact working-tree status identifies new files; plain `git diff --stat` excludes untracked additions until staged.

Permission caching is deliberately absent. Role lists are unpaginated. Permission keys require application/migration changes. Concurrent edits are serialized but use last successful write semantics rather than optimistic version conflicts. The internal assignment action must be called only after authorization by future membership workflows. Database tenant constraints do not replace those actor-level checks.

No invitations, member administration, membership removal/suspension, ownership transfer, organization deletion, audit trail, or ERP module is implemented. No dependency updates or infrastructure rebuilds were necessary. Tests ran locally, not on GitHub-hosted CI or a production deployment. Browser tests leave local test accounts, organizations, roles and Mailpit messages. Services remain running for review.

Phase 1.4 recommendation: design the invitation and membership lifecycle, then expose explicitly authorized role assignment through the existing tenant-safe domain operation, with escalation and owner-invariant tests. Do not delegate role administration implicitly. Phase 1.4 has not been started.
