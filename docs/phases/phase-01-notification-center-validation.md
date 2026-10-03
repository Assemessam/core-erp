# Phase 1.6 — Notification Center validation

## Checkpoint B: Persistence & Safety Contracts

Date: 2026-10-03. Status: **1.6B complete locally, awaiting review. Phase 1.6 is incomplete.** Only B was authorized and implemented; C was not begun. See [ADR 0008](../decisions/0008-notification-center-architecture.md) for the accepted design including the mandatory membership-era correction.

Preflight passed before edits: branch exactly feature/notifications, clean tracked/untracked working tree, HEAD 934b11b. Read AGENTS.md, README, ADRs 0005/0006/0007 and the approved 1.6A proposal/revision. Inspected membership schema/model, OrganizationAccess/provider/adapters, Audit contracts/access/writer, all historical migrations, architecture rules, PostgreSQL transactional/savepoint conventions and guarded disposable concurrency database tests. The existing healthy stack and installed locked dependencies were used. No staging, commit, push, branch switch, reset or discard occurred.

## Implementation and scope

```text
backend/app/Modules/Notification/
├── Application/
│   ├── Contracts/
│   │   ├── NotificationPublisher.php
│   │   └── NotificationOrganizationAccess.php
│   ├── Data/
│   │   ├── NotificationDraft.php
│   │   ├── NotificationTarget.php
│   │   ├── NotificationMembershipContext.php
│   │   └── NotificationText.php
│   ├── Vocabulary/
│   │   ├── NotificationType.php
│   │   └── NotificationTargetType.php
│   ├── Validation/NotificationPayloadValidator.php
│   ├── Content/NotificationTextRenderer.php
│   └── Exceptions/NotificationWriteFailed.php
└── Infrastructure/
    ├── Persistence/DatabaseNotificationPublisher.php
    └── Providers/NotificationServiceProvider.php
```

NotificationText is the concrete readonly title/body renderer result; it avoids returning an untyped pair. No Domain, Presentation, Eloquent model, future query/command folder or delivery abstraction was added.

Public publishing input is readonly organizationId, recipientUserId, typed type, payload, nullable typed target and payloadVersion. The publisher's SensitiveParameter draft contains no ID/time/copy/recipient membership. Notification-owned resolveActiveMembership(userId, organizationId) returns a readonly organization/user/membership context. OrganizationNotificationAccess checks OrganizationAccess and queries a fresh active persisted membership with explicit tenant/user predicates; only its provider/adapter consume Notification access contract/context. NotificationServiceProvider explicitly binds publisher to Query Builder writer; OrganizationServiceProvider binds access to the adapter; bootstrap registers the new provider.

The only type is organization.invitation_accepted/version 1. Exact payload: ULID invitation_id, canonical positive signed-bigint decimal string membership_id and positive integer accepted_user_id. Payload membership is the accepted invitee; recipient_membership_id is the independently resolved receiving membership. Credential-key normalization, unknown/missing key rejection, scalar/ID checks, UTF-8/NUL/depth limits and conservative 8192-byte encoding bound protect input. Plain server snapshots are Invitation accepted and User #<id> accepted an invitation and joined the organization. No email/name/template/model lookup exists. Only organization.users/null-ID and no-target/null-pair are permitted.

The writer requires the default pgsql connection with positive Laravel transaction level and physical PDO transaction; it controls no transaction or alternate connection. It verifies the returned context, validates payload/target, renders and inserts an internally generated ULID with database-created time and unread default. Invalid input/context, denied membership, rendering and actual SQL errors fail with fixed NotificationWriteFailed message and allowlisted category, no previous exception/binding/failing-row chain. Tests enable argument traces to verify sensitive snapshots/inputs are absent.

The new 13-column organization_notifications table matches ADR 0008. Organization/user FKs cascade deletion and restrict key updates. Positive historical recipient_membership_id has no live FK: deleting membership preserves both row and era. SQL checks protect ULIDs, positive IDs/version, stable type syntax, JSON object/8 KiB canonical text bound, bounded nonblank title/body, exact target/null combinations and read_at >= created_at or null. Only PK, recipient-user FK index, era-scoped descending chronology and era-scoped partial unread index are installed. Detailed payload/type/version schemas remain Application-owned.

Future list/count/mark-read/mark-all-read must resolve active context and scope organization_id + recipient_user_id + recipient_membership_id. B tests retain an old row after membership A removal, resolve distinct B on rejoin, and address a new row to B. They also preserve the membership ID through suspension/reactivation and deny suspended publication. These lower-level proofs do not implement or claim consumer API filtering; that is C's required runtime proof.

## Focused and regression evidence

Focused Notification tests ran first, after applying the single new migration, and passed **152 tests / 308 assertions**. The final full-suite JUnit report confirms every new test and architecture rule passes:

| Test file | Passed | Assertions |
| --- | ---: | ---: |
| Unit/Notification/NotificationPayloadValidatorTest | 78 | 106 |
| Unit/Notification/NotificationTextRendererTest | 9 | 11 |
| Feature/Notification/NotificationIntegrityTest | 46 | 98 |
| Feature/Notification/NotificationMembershipEraTest | 2 | 16 |
| Feature/Notification/NotificationPersistenceTest | 15 | 66 |
| Integration/NotificationTransactionRequirementTest | 2 | 11 |
| Architecture/BoundariesTest | 40 | 494 |

Integrity coverage includes independent raw CHECK/not-null/type bounds, both live FKs, canonical JSONB exactly 8192 bytes accepted/8193 rejected, safe target/null cases, read equality/after/before cases, organization/user cascade, retained positive historical scope, index/column/FK metadata, physical database recording time and new migration down/up. Schema test DDL remains inside outer Feature rollback; deferred constraints are flushed before DDL. No Audit protection is bypassed or history deleted for cleanup.

Publisher coverage includes trusted scope distinct from payload membership, explicit recipient despite ambient actor, owner requiring active membership, suspended/removed/nonmember/cross-tenant denial, mismatched trusted context rejection, generated IDs/default time/unread state, no-target support, caller rollback after success/validation/render/real insert failure, safe exception traces, no existing CreateOrganization producer, outside-transaction denial and a stale Laravel counter after physical PDO rollback.

Architecture now forbids Notification → Organization/Identity/Audit, framework/Infrastructure/HTTP/ambient context in Notification Application, Audit/Identity → Notification and Organization Domain/Application producer dependencies in B. Only named Organization adapter/provider exceptions import access contract/context. Other modules cannot import private Notification implementation. Directory and AST guards prohibit future B layers, transaction control, dispatch and named alternate connection in the publisher. Existing architecture guards remain active; static checks supplement runtime isolation tests.

## Commands and final results

Commands below run from the repository root. Independent frontend quality ran alongside backend tests; database suites were sequential and **did not overlap browser writes**. Logs/JUnit/browser artifacts were stored under /tmp, outside tracked source. Equivalent backend quality components were run individually so full Pest could emit JUnit.

| Command | Final result |
| --- | --- |
| `docker compose config --quiet`; `docker compose ps` | Valid; all five services healthy |
| `docker compose exec -T backend php artisan migrate --no-interaction` | Only 2026_10_03_000001 applied, batch 7 |
| `docker compose exec -T backend php vendor/bin/pest tests/Unit/Notification tests/Feature/Notification tests/Integration/NotificationTransactionRequirementTest.php --compact` | 152 passed / 308 assertions |
| `docker compose exec -T backend php vendor/bin/pest tests/Architecture --compact` | 40 passed / 494 assertions |
| `docker compose exec -T backend php vendor/bin/pest tests/Feature/Application/OrganizationAccessTest.php tests/Feature/Application/OrganizationWriteAuthorizationTest.php tests/Feature/Audit tests/Unit/Audit tests/Feature/Application/Auditing tests/Integration/AuditTransactionRequirementTest.php tests/Integration/InvitationAuditCommitTest.php tests/Integration/InvitationConcurrencyTest.php --compact` | 316 passed / 1592 assertions; fresh authorization, Audit storage/API/atomicity and real commit/concurrency regressions |
| `docker compose exec -T backend composer test -- --compact --log-junit=/tmp/coreerp-notification-b-tests.xml` | Final code: 633 passed / 3238 assertions; no failures/skips |
| `docker compose exec -T backend composer lint` | Pint: 184 files passed |
| `docker compose exec -T backend composer analyse` | Larastan level 8: 122 files, no errors/baseline |
| `docker compose exec -T backend composer dump-autoload --optimize --strict-psr --strict-ambiguous` | Passed; 9124 classes, no PSR/ambiguous failures |
| `docker compose exec -T backend composer validate --strict` | Valid |
| `docker compose exec -T backend composer check-platform-reqs` | All requirements passed (PHP 8.5.11) |
| `docker compose exec -T backend composer audit` | No security advisories |
| `docker compose exec -T frontend npm run quality` | ESLint/Prettier/TypeScript passed; 61 tests / 10 files passed; build passed, 125 modules |
| `docker compose exec -T frontend npm audit` | Zero vulnerabilities |
| `docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -v /tmp/coreerp-notification-b-browser-artifacts:/tmp/coreerp-playwright-results -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test` | Complete checked-in suite: 5 passed / 0 failed |
| `docker compose exec -T postgres pg_isready -U coreerp -d coreerp` | Accepting connections |
| `docker compose exec -T redis redis-cli ping` | PONG |
| `curl -fsS http://localhost:8088/api/v1/health`; `curl -fsS http://localhost:8088/api/v1/ready`; `curl -fsS http://localhost:5174/api/v1/ready` | HTTP 200, status ok for liveness/API/proxy |
| `curl -fsS http://localhost:8026/api/v1/info` | HTTP 200, Mailpit v1.27.1 reachable |
| `docker compose exec -T backend php artisan migrate:status` | All seven migrations Ran, historical batches unchanged; new migration batch 7 |
| `docker compose exec -T postgres psql -U coreerp -d coreerp -Atc "SELECT count(*) FROM organization_notifications"` | Zero after tests and normal browser flows |
| `docker compose exec -T postgres psql -U coreerp -d postgres -Atc "SELECT count(*) FROM pg_database WHERE datname = 'coreerp_concurrency_test'"` | Zero; guarded disposable database cleaned up |
| `git diff --check`; `git diff -- frontend`; `git diff --cached --name-only` | Clean whitespace; frontend diff empty; staged diff empty |
| `git diff --name-only -- backend/database/migrations`; `git ls-files --others --exclude-standard -- backend/database/migrations` | Historical migration diff empty; only the new notification migration untracked |

Formatting was scoped to all new PHP and the three modified PHP paths using Pint; final full Pint is read-only. Initial Larastan caught an always-true comparison for a single-case target enum; an exhaustive match fixed it, and final full Pest/static/Pint passed afterward. One attempted regression invocation referenced a nonexistent legacy test path and executed no regressions; the corrected existing paths passed as recorded above. No test implementation failure or unresolved gate remains.

## Changed files and preservation

Created 24 files: the 13 Notification files in the tree above; Organization/Infrastructure/Notification/OrganizationNotificationAccess.php; the new migration; the six Notification test files listed above plus tests/Support/NotificationFixtures.php; ADR 0008; this validation record.

Modified six files: OrganizationServiceProvider.php, bootstrap/providers.php, tests/Architecture/BoundariesTest.php, README.md, docs/architecture/system-overview.md and docs/phases/phase-01-core-platform.md. Provider changes only add explicit composition; architecture/documentation changes reflect B.

Existing Organization business commands, invitation SMTP behavior, Identity authentication/verification/reset mail, Audit source, policies/access, HTTP routes/controllers/requests/resources, frontend source/tests, dependency manifests/locks, environment files and Compose are unchanged. Historical migrations are byte-identical to HEAD. Browser-created users/business data/Audit history and ordinary credential mail remain normal local test data; no history cleanup, trigger disabling or app database reset was introduced. Notification storage contains no ordinary application rows.

## Review points, limits and next checkpoint

Review the trusted membership port/context assertion, absence of producer-supplied era/copy, historical no-FK trade-off, SQL CHECK null semantics, physical transaction guard, safe real insertion failures, exact payload/version/target validation and precise adapter/provider allowlists. The migration's down() is destructive and should not be used on populated storage as routine code rollback.

B does not implement list/read/count APIs, actual producer, frontend, email, queues/Horizon, retry/outbox, realtime/Reverb, preferences or purge. Privileged SQL can fabricate historical era and bypass detailed schemas; payload references are producer facts, not newly checked business relationships. Existing authorization-check races are unchanged: already-authorized work may finish during concurrent revocation. Caller orchestration must propagate failure to abort the transaction. No idempotency, tamper-proof evidence or retention-duration guarantee is added. All requested local gates were verified; hosted CI/production-scale plans/deployment hardening are outside this checkpoint.

Recommendation: after B review and explicit authorization, implement **1.6C — Notification Query & Read Lifecycle API** with fresh active membership context, mandatory organization/user/current-membership predicates on every list/count/read write, bounded pagination and read operations, and runtime cross-tenant/cross-recipient/old-era denial tests. Keep first AcceptInvitation producer for D. Stop at B now.
