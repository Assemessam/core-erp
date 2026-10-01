# Phase 1.5 — Audit Trail validation

Current checkpoint: **1.5D Invitation & Membership Lifecycle integration complete locally, awaiting review**. B/C are approved and committed as `2675976` / `c040582`. B/C results below are historical; the appended D record supersedes their integration status. Phase 1.5 is not complete and E has not begun.

## Phase 1.5B — Audit Persistence & Safety Contracts (historical)

Date: 2026-10-01. Status: implemented locally, awaiting review. Branch: `feature/audit-trail`. Nothing staged, committed or pushed; no branch switch/reset/discard. Phase 1.5C has not begun and Phase 1.5 is not complete.

## Inspection and baseline

Started from a clean working tree on the expected branch. Read AGENTS.md, ADRs 0005/0006, the approved Phase 1.5A proposal and checkpoint B instructions. Inspected Organization/Identity, migration history, architecture checks, Pest/PHPUnit configuration, raw PostgreSQL integrity tests, historical migration compatibility tests and InvitationConcurrencyTest's committed fixture cleanup. No Organization/Identity production files or existing test-isolation configuration changed.

Started the existing Compose stack with `docker compose up -d --wait`. Baseline `docker compose exec -T backend composer quality`: **149 passed / 1,218 assertions**, Pint 117 files, Larastan level 8 clean. Backend database tests ran sequentially, without overlapping browser writes.

## Implemented boundary

[ADR 0007](../decisions/0007-audit-trail-architecture.md) records the design and exact schemas. Audit has Application and Infrastructure only: one recording interface, three readonly input objects, three backed vocabulary enums, one validator, one safe exception, one Query Builder recorder and one explicitly registered provider. No Domain, Presentation, Eloquent audit model, history query, Policy, permission, route, Resource or frontend exists.

All eleven current Organization action schemas are defined, but **no business command calls AuditRecorder**. A regression assertion confirms organization creation emits no audit event in B. No historical event backfill is attempted.

The recorder validates action/subject/version, tenant and identifier shape, actor consistency and exact snapshot fields/types/transitions. It requires a positive Laravel transaction level plus an actual PDO transaction on the current default PostgreSQL connection. It generates ULID IDs, inserts explicit columns and leaves recording time to the database clock_timestamp() default. It neither starts nor commits transactions, switches connections, queues work nor uses afterCommit.

Validation and persistence failures propagate as AuditWriteFailed with a fixed safe message, allowlisted diagnostic category and no previous exception. Sensitive input parameters are annotated. A real FK-failure test enables exception argument rendering and checks the resulting exception string contains neither payload business text nor SQL. This does not claim control over privileged database logging/query listeners.

## Payload and database protections

Exact required/optional action-specific fields are primary. Recursive normalized prohibited-key rejection covers passwords/hashes/reset credentials, tokens/invitation-token hashes, CSRF/XSRF, sessions, cookies, authorization/credentials/secrets, API/access keys and encryption/private keys. Emails, requests, model dumps and mail bodies are not approved fields. Wrong scalar types, objects, floats, invalid UTF8, NUL bytes, missing/unknown fields and impossible transitions fail rather than being removed.

Limits: combined 65,536-byte conservative pretty/Unicode-escaped JSON encoding; PostgreSQL independently checks combined canonical JSONB text octets; depth 3 (root/field/list item); 1,000 collection items; generic string 1,024 characters; organization name 255/role name 80; field key 80 bytes. Relationship sets must already be sorted and distinct. Membership identifiers are canonical positive signed bigint strings. Expiration timestamps are canonical UTC with six fractional digits and independently validate in UTC, regardless of host timezone/DST. Only payload version 1 is accepted.

The installed Laravel 13 HasUlids generates lowercase model IDs. Initial focused tests caught an uppercase-only assumption; Application and SQL ULID checks now accept either case while preserving exact stored identifiers. Only the new audit migration was corrected/reapplied, after verifying the table contained zero rows. Existing models/migrations were not altered.

The new migration creates required tenant, explicit user/system actor, stable action/subject, before/after JSONB, version and database-created timestamptz(6), with no updated_at/deleted_at/metadata. Organization and actor foreign keys restrict delete/update; subjects have no polymorphic FK. SQL enforces structural identifier/actor/snapshot/version/size rules, not the full evolving action schemas.

Five indexes: primary key; tenant chronology; tenant action chronology; tenant subject chronology; actor FK support. No GIN or speculative metadata indexes.

One PL/pgSQL function raises fixed SQLSTATE 55000; two unconditional statement-level BEFORE triggers reject UPDATE/DELETE and TRUNCATE, including zero-row mutation statements. No bypass exists. Raw referenced actor/organization deletion returns PostgreSQL restrictive-reference SQLSTATE 23001; missing FK insertion returns 23503. Natural transaction rollback removes uncommitted inserts.

Migration down/up was tested both through the narrowly targeted new-migration rollback/reapply and through transactional Feature DDL. Triggers, function and table are mechanically removable, with **destruction of history** explicitly documented. Production code rollback should normally retain audit storage.

## Test isolation and coverage

No disposable-database helper or test configuration change was required. Feature tests use the existing outer DatabaseTransactions rollback with nested savepoints; no audit records are deleted for cleanup and no triggers are disabled. The transaction-requirement Integration tests need no committed fixture. Existing InvitationConcurrencyTest and migration compatibility tests pass unchanged. Future instrumented committed concurrency fixtures will need separately scoped defensive isolation support.

Complete-suite partitions relevant to B (not additional executions):

| Area | Passed cases |
| --- | ---: |
| Audit pure unit/schema/security | 93 |
| Audit persistence/Application contract | 6 |
| Audit real PostgreSQL integrity/immutability/migration | 33 |
| Audit absent/stale physical transaction integration | 2 |
| Architecture total, including prior guards | 21 |
| Complete backend total | 290 |

Coverage includes every approved schema; enum-only actions/subjects; unknown/missing fields; normalized recursive credential keys; malformed/cross-organization organization subjects; invalid actors/types/IDs; total size/depth/collection/string/JSON bounds; stable membership identities; real calendar/DST validation; no-op/invalid transitions; insertion and same-transaction rollback; persistence failure rolling back earlier writes; user/system attribution and explicit tenant separation; statement-level zero-row UPDATE/DELETE and TRUNCATE rejection; raw FK/actor/JSON/size constraints; index/timestamp/trigger structure; migration down/up; safe error chain/arguments; no application instrumentation.

Architecture checks prohibit Audit -> Organization/Identity dependencies, all framework/Infrastructure/Presentation dependencies in Audit Application, Organization Domain -> Audit, foreign module imports of private Audit implementations, artificial B layers, and recorder transaction/afterCommit/dispatch ownership. The default connection call is checked to have no alternate connection argument. Existing controller/domain/application/ambient-context guards remain active. These syntax/dependency tests supplement runtime security checks; they do not prove subject authority or future producer correctness.

## Final commands and results

Commands ran from repository root. Focused runs were used during implementation; final complete results below supersede intermediate failures. No backend database suite ran concurrently with browser writes.

| Command | Final result |
| --- | --- |
| `docker compose exec -T backend composer quality` | **290 passed / 1,613 assertions**, no skips; Pint 134 files; Larastan level 8, 91 files, no errors |
| `docker compose exec -T backend php vendor/bin/pest tests/Unit/Audit --compact` | Focused schema/security iteration passed; final 93 cases are included in complete quality |
| `docker compose exec -T backend php vendor/bin/pest tests/Unit/Audit tests/Feature/Audit tests/Integration/AuditTransactionRequirementTest.php tests/Architecture --compact` | Focused pre-final run: 153 passed / 647 assertions; two subsequent unit cases pass in complete suite |
| `docker compose exec -T backend composer format` | Only two new Audit files required formatting; final Pint clean |
| `docker compose exec -T backend composer analyse` | Focused static analysis clean; final result included in quality |
| `docker compose exec -T backend composer dump-autoload --optimize --strict-psr` | Passed, 9,091 classes |
| `docker compose exec -T backend composer validate --strict` | Valid |
| `docker compose exec -T backend composer check-platform-reqs` | All requirements passed |
| `docker compose exec -T backend composer audit` | No security vulnerability advisories |
| `docker compose exec -T frontend npm run quality` | ESLint/Prettier/TypeScript, **9 Vitest files / 45 tests**, production build (122 modules) passed |
| `docker compose exec -T frontend npm audit` | Zero vulnerabilities |
| `docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test` | **4 passed**, existing auth/isolation/RBAC/real-Mailpit invitation lifecycle flows |
| `docker compose config --quiet`, `docker compose up -d --wait`, `docker compose ps` | Valid; all five services healthy |
| `docker compose exec -T postgres pg_isready -U coreerp -d coreerp` | Accepting connections |
| `docker compose exec -T redis redis-cli ping` | PONG |
| `curl -fsS http://localhost:8088/api/v1/ready` | HTTP 200, status ok |
| `curl -fsS http://localhost:5174/api/v1/ready` | HTTP 200, proxy status ok |
| `curl -fsS http://localhost:8026/api/v1/info` | HTTP 200, Mailpit reachable |
| `docker compose exec -T backend php artisan migrate --no-interaction` | Only new audit migration applied |
| `docker compose exec -T backend php artisan migrate:rollback --path=database/migrations/2026_10_01_000002_create_audit_events_table.php --step=1 --no-interaction` | Only the empty new audit table rolled back, followed by successful migrate/reapply |
| `docker compose exec -T backend php artisan migrate:status` | All five migrations Ran; audit migration batch 5 |
| `git diff --check` | Passed |
| `git diff -- frontend` | Empty |
| `git diff -- backend/database/migrations` | Empty for tracked files; the only new migration is untracked, listed below |
| Organization/Identity production, existing concurrency/config, cached diff checks | Empty; nothing staged |

Intermediate test corrections were limited to B: case-preserving ULID compatibility, explicit UTC parsing, PostgreSQL RESTRICT's actual SQLSTATE, and test assertion/closure fixes. Static-analysis nullability findings were resolved using validated local snapshots without suppressions. No failing gate remains.

## Files and review points

Created 19 files: eleven Audit module PHP files; one new migration; five Audit test/support PHP files; ADR 0007; this validation record. Modified five tracked files: bootstrap/providers.php, BoundariesTest.php, README, system overview and Phase 1 roadmap. No frontend, Organization/Identity source, permission enum, historical migration, Composer/npm lock, auth, route or environment file changed.

Review the exact per-action fields/transitions, conservative size encoding, lowercase identifier preservation, user/system consistency, exception-chain sanitization, both physical/logical transaction checks, restrictive FKs and unconditional statement-level triggers. The initial audit permission-list vocabulary mirrors only currently approved keys without importing Organization Domain; future permission additions need explicit schema review.

## Limitations and stop point

Recording does not yet occur in real workflows. Subject ownership and actor authorization belong to later producer commands, not the recorder. No read API or cross-tenant history-access behavior is claimed. PostgreSQL/schema administrators can bypass/drop protections; malicious SQL can insert false facts or unaudited business mutations. This is not cryptographic tamper-proofing or an external provenance/completeness guarantee.

Production runtime/migration role separation and real deployment remain unverified; current Compose credentials are administrative development credentials. Free-text allowlists cannot detect every deliberately embedded secret. Indefinite retained IDs/names need a future privacy/retention decision. No archival infrastructure, production load test or hosted CI was performed.

Previously deferred invitation UX/delivery recovery/README test wording, CORS methods, framework 404 wording, external AUTH_MODEL/cache deployment note and sequential PostgreSQL constraint remain unchanged.

Recommendation for separately authorized 1.5C: add module-owned Organization projections and instrument CreateOrganization, RenameOrganization and SaveRole. Preserve HTTP/return semantics, use fresh locked before snapshots, skip no-ops and prove audit-failure rollback for each command. **Do not begin C until B is reviewed and explicitly authorized.**

## Final Git output

`git status --short`:

```text
 M README.md
 M backend/bootstrap/providers.php
 M backend/tests/Architecture/BoundariesTest.php
 M docs/architecture/system-overview.md
 M docs/phases/phase-01-core-platform.md
?? backend/app/Modules/Audit/
?? backend/database/migrations/2026_10_01_000002_create_audit_events_table.php
?? backend/tests/Feature/Audit/
?? backend/tests/Integration/AuditTransactionRequirementTest.php
?? backend/tests/Support/AuditFixtures.php
?? backend/tests/Unit/Audit/
?? docs/decisions/0007-audit-trail-architecture.md
?? docs/phases/phase-01-audit-trail-validation.md
```

`git diff --stat` (tracked files only; all new files remain untracked):

```text
 README.md                                     |  4 +--
 backend/bootstrap/providers.php               |  2 ++
 backend/tests/Architecture/BoundariesTest.php | 42 +++++++++++++++++++++++++++
 docs/architecture/system-overview.md          |  4 ++-
 docs/phases/phase-01-core-platform.md         |  4 +--
 5 files changed, 51 insertions(+), 5 deletions(-)
```

## Phase 1.5C — Organization & RBAC Audit Integration (historical)

Date: 2026-10-01. Status: complete locally, awaiting review. Started clean on `feature/audit-trail`, with approved B commit `2675976 feat: add immutable audit persistence foundation`. No staging, commit, push, branch change, reset or discard. Only C is implemented; D and later API/UI checkpoints remain pending.

### Inspection and baseline

Read AGENTS.md, ADRs 0005/0007 and the existing validation record; inspected Audit input/vocabulary/validator/recorder, OrganizationAccess/models, CreateOrganization/RenameOrganization/SaveRole, Organization/RBAC/Application/security tests, migration compatibility and committed invitation concurrency cleanup. All five Compose services were already healthy. Baseline `docker compose exec -T backend composer quality`: **290 passed / 1,613 assertions**, Pint 134 files and Larastan level 8 clean.

### Factory and public dependency inventory

One Organization-owned, model-free `Application/Auditing/OrganizationAuditEntries` exposes organizationCreated, organizationRenamed, roleCreated and roleUpdated. Inputs are explicit scalar business values and string permission lists. It constructs readonly Audit entries with deliberate field projections, sorts/deduplicates permissions and returns null when role update fields are unchanged. Audit remains responsible for validation/persistence; Organization remains responsible for authorization and subject scope.

All Organization-to-Audit imports, checked with `rg -n '^use App\\Modules\\Audit' backend/app/Modules/Organization`:

| Organization consumer | Audit dependency |
| --- | --- |
| CreateOrganization, RenameOrganization, SaveRole | Application/Contracts/AuditRecorder (one import each) |
| OrganizationAuditEntries | Application/Data/AuditActor, AuditEntry, AuditSubject |
| OrganizationAuditEntries | Application/Vocabulary/AuditAction, AuditSubjectType |

Eight imports / six distinct public types. No Audit internals, foreign Eloquent types, table access, ambient actor/tenant or request serialization enters Organization auditing. No Audit module production file was changed.

### Command behavior and exact persisted facts

CreateOrganization retains its existing transaction, creates the owner membership, then records exactly one organization.created fact using the trusted owner and actual persisted owner membership ID. Rename now owns a transaction and reloads/locks the authoritative organization row by ID. Stale supplied A / persisted B / requested C records B to C, never A to C. No-op comparison uses B. The same supplied model instance is returned, with persisted attributes synchronized only after success; dirty ownership is never saved.

SaveRole retains scoped lookup/locking, owner-only persisted authorization, trimmed names, typed PermissionKey inputs, grants synchronization and PostgreSQL duplicate-name translation. Creation records after role/grants persistence. Update captures fresh locked name and persisted permissions, then projects only changed fields. Names and permission sets are compared consistently; equivalent trimmed names and sorted unique grants emit no role.updated. Duplicate create/update failure emits no successful fact and preserves HTTP 422.

All four paths persist tenant ID, user actor type/trusted ID, stable action, subject type/persisted ID, payload_version=1, a generated ULID and database recording timestamp. Snapshot fields are exact:

| Action | Before | After |
| --- | --- | --- |
| organization.created | null | name, owner_user_id, owner_membership_id |
| organization.renamed | name | changed persisted name |
| role.created | null | trimmed persisted name, sorted unique permissions |
| role.updated | changed name and/or permissions only | matching keys, each changed |

No email, credentials, invitation data, HTTP metadata or model dumps. No ownership-bootstrap or pivot-internal duplicate events. OrganizationAccess, external API/resources/status codes, controllers and Policies remain unchanged.

### Atomicity, security and test fixtures

The 24 new Application cases cover exact rows for all facts; explicit/delegated actors despite unrelated sessions; forged HTTP attribution and prohibited owner fields; authoritative stale state for both rename and role; name-only, permissions-only, combined and empty-permission changes; no-ops; duplicate failures; direct unauthorized/cross-tenant attempts; supplied-instance compatibility and audited organization deletion restriction.

Eight failure cases cover all four command paths in two modes. A local test-only recorder throws AuditWriteFailed after checking business changes already exist in the caller transaction. A decorator mode calls the real DatabaseAuditRecorder, asserts its successful INSERT and deliberately throws before command transaction completion. Both modes prove rollback of organization/owner membership, rename, role creation/grants or role name/grants update, plus audit history. Production failures propagate; no production hook, no-op adapter, best-effort catch, queue, observer or afterCommit write exists. Recorder live-transaction checks are unchanged.

Most existing setup keeps calling the real audited commands. Narrow exceptions use the new test-only OrganizationFixtures::unaudited helper: Audit storage/integrity fixtures, the historical RBAC cascade test and invitation concurrency setup. Cascade roles also use raw persistence so its original organization deletion intent remains valid. The obsolete B assertion that command creation emits no event was replaced by C success coverage. A separate C test proves audited creation now restricts organization hard deletion.

Feature tests retain DatabaseTransactions and nested savepoints; no PHPUnit/database configuration changed. Invitation concurrency still runs two real PostgreSQL acceptance sessions, observes both waiting and verifies one acceptance plus one replay rejection, then cleans only its raw identified fixtures. Invitation acceptance is uninstrumented in C. No disposable database infrastructure was needed. No FK/trigger disabling or audit deletion cleanup occurs. Revisit safe dedicated disposable databases in D when committed acceptance starts retaining history. Database suites ran sequentially, separately from browser writes. Mandatory stale-state tests pass; no new competing-process rename/role test was added.

### Architecture and documentation

Architecture adds an explicit Organization-to-Audit Contracts/Data/Vocabulary namespace allowlist and rejects any Audit dependency in Organization Infrastructure/Presentation; Domain stays Audit-independent. Private Audit isolation and all prior rules remain. Audit still has no Domain, Eloquent model, Presentation or query layer, and recorder transaction control is unchanged.

ADR 0005 narrowly explains why single-write rename now needs an atomic business-write/audit-insert transaction under ADR 0007. ADR 0007 records implemented C projections, locks, no-ops, failures and fixture decisions. System overview, roadmap and README now describe B as approved/committed, C as integrated locally, and D/history API/UI as pending. This validation file preserves B's historical results and appends C. Phase 1.5 is not marked complete.

### Commands and final results

Commands ran from repository root; final results supersede intermediate corrections.

| Command | Result |
| --- | --- |
| `docker compose exec -T backend php vendor/bin/pest tests/Feature/Application/Auditing --compact` | **24 passed / 278 assertions** |
| `docker compose exec -T backend php vendor/bin/pest tests/Feature/Audit tests/Unit/Audit tests/Integration/AuditTransactionRequirementTest.php --compact` | **133 passed / 316 assertions** |
| `docker compose exec -T backend php vendor/bin/pest tests/Feature/Application tests/Feature/OrganizationsTest.php tests/Feature/RbacTest.php tests/Feature/OrganizationUsersTest.php tests/Feature/OrganizationUsersIntegrityTest.php tests/Integration/InvitationConcurrencyTest.php --compact` | **110 passed / 883 assertions**, including C cases |
| `docker compose exec -T backend php vendor/bin/pest tests/Architecture --compact` | **23 passed / 339 assertions** |
| `docker compose exec -T backend composer quality` | **315 passed / 1,895 assertions**, no skips; Pint 137 files; Larastan level 8, 92 files, clean |
| `docker compose exec -T backend composer format` | Formatting applied only to C PHP changes; final Pint passed |
| `docker compose exec -T backend composer analyse` | Clean, without suppressions |
| `docker compose exec -T backend composer dump-autoload --optimize --strict-psr` | Passed, 9,093 classes |
| `docker compose exec -T backend composer validate --strict` | Valid |
| `docker compose exec -T backend composer check-platform-reqs` | All requirements passed |
| `docker compose exec -T backend composer audit` | No security vulnerability advisories |
| `docker compose exec -T frontend npm run quality` | ESLint, Prettier, TypeScript, **9 Vitest files / 45 tests**, production build (122 modules) passed |
| `docker compose exec -T frontend npm audit` | Zero vulnerabilities |
| `docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test` | **4 passed**, existing authentication, isolation, RBAC and real-Mailpit membership lifecycle flows |
| `docker compose config --quiet`, `docker compose ps` | Valid; all five services healthy |
| `docker compose exec -T postgres pg_isready -U coreerp -d coreerp` | Accepting connections |
| `docker compose exec -T redis redis-cli ping` | PONG |
| `curl -fsS http://localhost:8088/api/v1/ready` | HTTP 200, status ok |
| `curl -fsS http://localhost:5174/api/v1/ready` | HTTP 200, proxy status ok |
| `curl -fsS http://localhost:8026/api/v1/info` | HTTP 200, Mailpit reachable |
| `docker compose exec -T backend php artisan migrate:status` | All five existing migrations Ran, audit migration batch 5 |
| `git diff --check` | Passed |
| `git diff -- backend/database/migrations`, `git diff -- frontend` | Both empty |
| `git diff --cached --stat` | Empty, nothing staged |

Initial combined focused invocation repeated a parent/child test path; reran with disjoint paths. One HTTP test correction preserved the existing prohibited owner_user_id behavior. Larastan required typed persisted Permission mapping plus array_values to establish list<string>; resolved without suppressions. Final quality has no failing gate.

### Changed files, manual review and stop point

Created three PHP files: OrganizationAuditEntries, OrganizationAuditIntegrationTest and OrganizationFixtures. Modified the three audited commands; BoundariesTest; AuditPersistenceTest/AuditIntegrityTest; RbacTest; InvitationConcurrencyTest; ADRs 0005/0007; system overview; roadmap; this validation record; README. No migration, permission catalog, Audit production, Identity production, OrganizationAccess, HTTP adapter, invitation/lifecycle command, frontend source, dependency lock or environment change.

Review the fresh locked-state snapshots, rename's post-success supplied-instance synchronization, changed-field-only role projection, sorted unique permissions, trusted actors/scoped subjects and the intentional audited-organization deletion restriction. Raw fixtures are confined to tests of storage/historical cascades/committed uninstrumented invitation locking. Browser-created audit history is retained normally; it is not deleted as cleanup.

Limitations remain: privileged SQL can bypass command instrumentation or fabricate facts; administrators can bypass storage protections; free-text names cannot universally detect embedded secrets; production privilege separation/load/deployment/retention are unverified. No read API/history-access claim, audit.view, frontend history or production deployment is included. Existing sequential database constraints and deferred operational/UX/CORS issues remain.

Recommend separately authorized **1.5D Invitation & Membership Lifecycle audit integration**: extend deliberate module-owned projections and existing command transactions, preserve credential/email exclusions and mail-after-commit semantics, prove rollback/no-op/reinvite facts, and address committed invitation concurrency test isolation before auditing acceptance. **Stop here; D has not begun.**

## Phase 1.5D — Invitation & Membership Lifecycle Audit Integration

Date: 2026-10-01. Status: complete locally, awaiting review. Started clean on `feature/audit-trail`, approved C commit `c040582 feat: audit organization and RBAC mutations`. Nothing staged/committed/pushed; no branch change/reset/discard. Only D is implemented. E and the history UI remain pending; Phase 1.5 is not complete.

### Inspection and baseline

Read AGENTS.md, ADRs 0005/0006/0007 and this record. Inspected Audit inputs/schemas/recorder, OrganizationAuditEntries, all seven lifecycle commands, AssignMembershipRole/ResolveOrganizationRoles/LockManagedMembership, Domain rules, InvitationDelivery/F1 tests, OrganizationUsers tests/constraints, raw fixtures and the genuine committed acceptance test. Existing Compose services were healthy. Baseline `docker compose exec -T backend composer quality`: **315 passed / 1,895 assertions**, Pint 137 files and Larastan level 8 clean.

### Implemented facts and public dependency inventory

The existing model-free OrganizationAuditEntries now has seven additional named methods: invitationCreated, invitationRevoked, invitationAccepted, membershipRolesChanged, membershipSuspended, membershipActivated and membershipRemoved. Optional replacement ID adds only the approved paired reason=replaced/replacement_invitation_id fields. Methods take explicit scalar facts and role-ID lists; none accept models, requests, emails, credentials or arbitrary payload arrays. Role sets become sorted unique stable strings; equivalent before/after membership role sets return null.

All Organization-to-Audit imports were inspected with `rg -n '^use App\\Modules\\Audit' backend/app/Modules/Organization`. Ten commands (the three C commands plus seven D commands) import only Contracts/AuditRecorder. The one factory imports Data/AuditActor, AuditEntry, AuditSubject and Vocabulary/AuditAction, AuditSubjectType. **Fifteen imports / six distinct public types**. No Audit Infrastructure, Validation, Exceptions, Presentation or direct audit_events access in Organization production. No Audit module production change; architecture allowlists remain unchanged.

All events persist organization_id, user actor type/trusted explicit user ID, stable action, correct subject type/persisted ID, exact snapshots, payload_version=1, generated ULID and database-created timestamp. D snapshots are:

| Action | Before | After |
| --- | --- | --- |
| invitation.created | null | state=pending, expires_at in canonical UTC with six fractional digits, persisted role_ids |
| invitation.revoked | state=pending | state=revoked; replacement only adds replacement_invitation_id and reason=replaced |
| invitation.accepted | state=pending | state=accepted, persisted membership_id, accepting user_id, actual assigned role_ids |
| membership.roles_changed | user_id, old persisted role_ids | same user_id, new persisted role_ids |
| membership.suspended | user_id, status=active | same user_id, status=suspended |
| membership.activated | user_id, status=suspended | same user_id, status=active |
| membership.removed | user_id, persisted status, persisted role_ids | null |

All role lists are sorted and unique. All eleven mutation facts are now integrated: organization.created/renamed, role.created/updated, invitation.created/revoked/accepted and membership.roles_changed/suspended/activated/removed. No independent grant/bootstrap events, attempted/expiration events or global authentication instrumentation.

### Transactional orchestration, fresh state and no-ops

CreateInvitation preserves authorization/reauthorization, organization lock, email/member rules, secure credential rotation, role resolution/grants, expiration and synchronous after-commit SMTP delivery. Recording occurs after invitation/grants persist, before commit. It refreshes the invitation before projection so audit expiration describes actual PostgreSQL/Laravel stored precision and timezone rather than transient microseconds. Reinvite first revokes the old credential and creates the new invitation; with both IDs known it records old revocation then new creation in one transaction, without a third event.

RevokeInvitation records actual pending-to-revoked only; accepted/already-revoked remain no-ops. Acceptance preserves token, verified matching email, fresh pending/unexpired state, organization-first/invitation locks, unique membership, grants, accepted state and replay denial. It records exactly one fact after all writes, using the accepting actor and actual assigned grants. Role sync reads the locked membership's persisted roles before mutation and actual roles afterward. Status commands read locked status and skip repeats without weakening owner protection. Removal captures locked user/status/roles before deletion and records after deletion succeeds; history intentionally survives the deleted subject.

Stale-state coverage includes invitation state, cached membership roles and membership status changed independently in PostgreSQL. Existing direct authorization, HTTP identity spoofing, owner protection and cross-tenant tests remain active. Explicit audit actors are proven despite unrelated ambient sessions; delegated issuance/revocation use the caller, not invitation inviter/member identity.

### Rollback and mail/privacy proofs

Explicit failures cover new issuance, each replacement audit insert, revoke, acceptance, complete role sync, suspension, activation and removal. Rollback restores invitation/grants/hash persistence, old pending credential, membership/grants/accepted state, previous complete role set, status/access or deleted membership/pivots. Failed replacement's old credential is demonstrated still acceptable. No successful fact remains from failure. A real acceptance audit INSERT followed by deliberate exception rolls membership/grants/state/history back together. Production exceptions propagate; no recorder bypass, best-effort catch, observer, queue or afterCommit audit write exists.

Mail callback registration remains before audit insertion. Feature and physical-commit Integration tests prove rollback discards it, including failed second replacement insertion, and a later successful transaction does not deliver abandoned mail. A real InvitationDelivery using a failing effective SMTP transport confirms delivery begins only with transaction level zero/PDO out of transaction and audit persisted. HTTP remains the existing safe 503; invitation and audit remain committed. invitation.created asserts issuance, not delivery; no mail event is added. F1 tests run unchanged.

Exact schema comparisons plus a scan across replacement/create/accept payload JSON prove no plaintext token, token hash, invited/actor email or invitation URL. Assertions use boolean comparisons to avoid sensitive actual-value dumps. Invited/accepting email arguments now have SensitiveParameter protection; the existing token annotation remains. With zend.exception_ignore_args=0, issuance and acceptance audit-failure traces are proven free of email/token/hash, with fixed message/no previous exception. No validator/schema weakening or delivery-security change.

### Committed concurrency and defensive database isolation

Audited acceptance makes organization/user deletion cleanup invalid. The real two-process test now runs inside test-only DisposableConcurrencyDatabase, uses audited organization creation, observes both independent PostgreSQL sessions waiting on the tenant lock and verifies one success, one accepted replay rejection, one membership, accepted invitation and exactly one invitation.accepted fact with exact attribution/snapshots/version/time. It retains genuine competition, not sequential replay.

phpunit.xml explicitly forces COREERP_CONCURRENCY_TEST_DATABASE=coreerp_concurrency_test. The helper requires testing environment, that exact fixed name, a nonempty distinct configured and actual application database, idle default pgsql, no URL override and no pre-existing reserved admin configuration. A separate administrative connection to postgres creates only an absent target. It refuses existing targets and never drops them; only its successfully created target is dropped as a whole in finally after workers exit, restoring original configuration. Workers require testing, fixed target and distinct explicit application database. No arbitrary destructive target, FK/trigger disabling, testing audit bypass or audit-row deletion.

Nine unit cases cover allowed/unsafe names, non-testing environment, development/production names, empty/default collisions and SQL identifiers. A failure Integration test proves drop and config restoration when its callback throws. Concurrency plus isolation checks pass **11 cases / 42 assertions**. Normal runs leave no disposable DB; final pg_database count is zero. This scope also supports three physical mail/commit tests. Existing Feature rollback strategy/raw integrity fixtures remain unchanged; committed immutable fixtures do not accumulate.

Run sequentially from repository root:

```bash
docker compose exec -T backend php vendor/bin/pest tests/Integration/InvitationConcurrencyTest.php --compact
docker compose exec -T backend php vendor/bin/pest tests/Integration/InvitationAuditCommitTest.php tests/Integration/DisposableConcurrencyDatabaseTest.php tests/Unit/DisposableConcurrencyDatabaseTest.php --compact
```

The configured PostgreSQL test role needs CREATE DATABASE and ownership/drop rights for the dedicated target plus access to the postgres maintenance database; Compose development credentials provide these. Do not run these suites in parallel. A killed process can leave the dedicated target behind; the helper refuses automatic reuse/drop, so verify ownership/purpose before manual recovery. No development/production database deletion is authorized by this helper.

### Documentation, changed files and final checks

Updated ADR 0007, architecture overview, roadmap, README and this validation record to describe complete mutation recording through D and pending E/API/UI. Architecture source did not need changes: the existing public namespace boundary covers all new imports without broadening. No historical/new migration, Audit schema/validator/recorder, OrganizationAccess, Domain rule, internal operation, HTTP adapter, InvitationDelivery/F1 implementation, frontend source, dependency lock or environment file change.

Modified eight Organization production files (factory plus seven commands), phpunit.xml, concurrency test and worker, and five documentation files. Created seven test/support files: InvitationAuditIntegrationTest, MembershipAuditIntegrationTest, InvitationAuditCommitTest, DisposableConcurrencyDatabase (support), its Unit/Integration tests and LifecycleAuditAssertions. Existing command-based setup stays audited; the raw fixture helper is not broadened or used as a production bypass.

| Command | Final result |
| --- | --- |
| `docker compose exec -T backend php vendor/bin/pest tests/Feature/Application/Auditing/InvitationAuditIntegrationTest.php tests/Feature/Application/Auditing/MembershipAuditIntegrationTest.php tests/Integration/InvitationAuditCommitTest.php --compact` | **42 passed / 391 assertions** |
| `docker compose exec -T backend php vendor/bin/pest tests/Integration/InvitationConcurrencyTest.php tests/Integration/DisposableConcurrencyDatabaseTest.php tests/Unit/DisposableConcurrencyDatabaseTest.php --compact` | **11 passed / 42 assertions** |
| `docker compose exec -T backend php vendor/bin/pest tests/Feature/InvitationDeliveryTest.php --compact` | **6 passed / 53 assertions**, unchanged F1 tests |
| `docker compose exec -T backend php vendor/bin/pest tests/Feature/Audit tests/Unit/Audit tests/Integration/AuditTransactionRequirementTest.php tests/Feature/Application/Auditing/OrganizationAuditIntegrationTest.php --compact` | **157 passed / 594 assertions** (Audit 133; C integration 24) |
| `docker compose exec -T backend php vendor/bin/pest tests/Architecture --compact` | **23 passed / 339 assertions** |
| `docker compose exec -T backend composer quality` | **367 passed / 2,318 assertions**, no skips; Pint 144 files; Larastan level 8, 92 files, no errors |
| `docker compose exec -T backend composer format`, `composer analyse` | Formatting/static checks clean; final quality supersedes initial focused analysis |
| `docker compose exec -T backend composer dump-autoload --optimize --strict-psr` | Passed, 9,095 classes |
| `docker compose exec -T backend composer validate --strict`, `composer check-platform-reqs`, `composer audit` | Valid/all requirements passed/no security advisories |
| `docker compose exec -T frontend npm run quality`, `npm audit` | All quality checks/build passed; **45 tests / 9 files**; zero vulnerabilities |
| `docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test` | **4 passed**, existing authentication/isolation/RBAC/real-Mailpit lifecycle flows |
| `docker compose config --quiet`, `docker compose ps` | Valid, five services healthy |
| `docker compose exec -T postgres pg_isready -U coreerp -d coreerp`, `docker compose exec -T redis redis-cli ping` | Accepting connections / PONG |
| `curl -fsS http://localhost:8088/api/v1/ready`, `curl -fsS http://localhost:5174/api/v1/ready`, `curl -fsS http://localhost:8026/api/v1/info` | HTTP 200; API/proxy status ok; Mailpit reachable |
| `docker compose exec -T backend php artisan migrate:status` | All five existing migrations Ran; audit batch 5 |
| `docker compose exec -T postgres psql -U coreerp -d postgres -Atc "SELECT count(*) FROM pg_database WHERE datname = 'coreerp_concurrency_test'"` | Zero; dedicated DB removed |
| `git diff --check`, migration/frontend/cached diff checks | Clean; migrations/frontend/staged changes empty |

Initial corrections were test-only owner-exception naming and an accidentally overlapping focused run that hit existing-target/create-collision protections. Sequential rerun passed; no foreign target was dropped. Complete backend validation ran in one sequential suite, and browser writes did not overlap backend database tests. Final results above supersede intermediate failures. No failing gate remains.

### Manual review, limitations and stop point

Review replacement's paired event ordering/final ID, persisted expiration precision, actual sorted role sets, accepting versus managing actor attribution, fresh locked state, no-op/owner protections, removal-after-delete recording and mail/audit separation. Review the guarded create/drop code and worker environment before changing test isolation; the fixed target is deliberately not parallel-safe. Browser-created application history is retained normally.

Known limitations: a forcibly killed disposable run may require manually verified cleanup; test database create/drop privileges are required. PostgreSQL administrators can bypass storage protections and privileged SQL can fabricate/omit facts; no cryptographic completeness guarantee. Production privilege separation, retention/privacy, load and hosted deployment remain unverified. No authorized history query, audit.view or UI exists; no production deployment, queue/outbox, expiration or global authentication audit was added.

Recommend separately authorized **1.5E — Authorized Audit Query API**: introduce the approved narrow Audit-owned authorization port implemented by Organization, audit.view, tenant-scoped bounded cursor reads and minimal filters/Resources, with direct/API authorization and cross-tenant denial tests. **Stop here; E has not begun.**
