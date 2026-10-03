# Phase 1.6 — Notification Center validation

Current status: B approved/committed as 1627633; **C complete locally, awaiting review**. Phase 1.6 remains incomplete; producer D and frontend E are pending. The B section below preserves its original checkpoint evidence; C evidence follows it.

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

## Checkpoint C: Notification Query & Read Lifecycle API

Date: 2026-10-03. Status: **complete locally, awaiting review**. Started from clean feature/notifications at the approved B commit 1627633. AGENTS.md, README, ADRs 0005–0008, B's complete module/migration/fixtures, OrganizationAccess/access adapter, Audit query/pagination/HTTP precedent, exception bootstrap, routes, Resources/Form Requests and architecture conventions were inspected before editing. The stack was stopped initially; docker compose up -d --wait restored the five existing services, then the unchanged baseline passed. No migration/install/branch operation was needed. No staging, commit, push, reset or discard occurred.

### Consumer implementation and public contract

Added NotificationReader (read and unreadCount) and NotificationReadStore (markRead and markAllRead) as internal semantic persistence ports, bound explicitly in NotificationServiceProvider. Application entry points are ListNotifications, GetUnreadNotificationCount, MarkNotificationRead and MarkAllNotificationsRead. ResolveNotificationMembership is a concrete shared helper so all four independently resolve active context and assert organization/user agreement and positive membership ID without duplicating that check. No ambient auth/request/session, foreign module source, Domain, aggregate, Eloquent model or generic repository is introduced.

The four HTTP routes require auth:sanctum and verified middleware. Organization/notification route values are structural ULID strings, with no Organization model binding; read-all is registered before the item route. NotificationController adapts trusted actor ID and invokes Application; it uses no database/transaction/producer API. ListNotificationsRequest resolves active context before validation, following Audit's authorization-first pattern without importing it. Application repeats fresh authorization/validation independently. This deliberate second small access check preserves direct-call security and sees membership changes after HTTP validation.

| Method | Path under /api/v1 | Response |
| --- | --- | --- |
| GET | /organizations/{organization}/notifications | data array; meta next_cursor/has_more/per_page |
| GET | /organizations/{organization}/notifications/unread-count | data.unread_count integer |
| POST | /organizations/{organization}/notifications/{notification}/read | 204, including replay |
| POST | /organizations/{organization}/notifications/read-all | 204, including zero unread |

There is no notification permission or can_view_notifications capability. Active ordinary members and owners access only their own current membership era; ownership cannot expose another recipient. Guest/unverified yield 401/403; suspended/removed/nonmember/foreign membership yield hidden 404. Foreign recipient/tenant/old-era/nonexistent item IDs in an accessible organization share exactly {message: Notification not found.}. NotificationNotFound is not reported as an operational error. Query errors are fixed standard 422 field errors; NotificationStorageFailed provides safe 503 without a retained SQL/binding exception chain or debug response. It is the additional concrete error needed for reader/store failures.

Every notification SELECT, count, conditional UPDATE and replay EXISTS uses organization_id + recipient_user_id + recipient_membership_id from trusted resolved context. No operation accepts recipient ID, membership ID or read timestamp from client input. Mutation body fields have no effect; list rejects unsupported scope/filter keys. The B adapter/publishing API/writer/validator/renderer/schema are unchanged.

Only cursor/per_page are supported; per_page defaults to 25 and is bounded 1–100. Notification-owned cursor is version-1 canonical unpadded base64url JSON with exact v/created_at/id fields, maximum encoded length 256, strict JSON/scalar/duplicate-key checks, real UTC calendar time with six fractional digits and structural ULID. Fixed failures do not echo input. No HMAC is added: cursor navigation never grants authority. Foreign organization, other recipient and removed-era cursor replay cannot remove scope predicates.

List applies a grouped (created_at < cursor_time OR (created_at = cursor_time AND id < cursor_id)) predicate after all three scope predicates, orders created_at DESC/id DESC and fetches at most per_page+1. It projects only explicit public columns, never payload/scope IDs, SELECT *, OFFSET, joins, JSON filters or total counts. The extra row determines has_more; next_cursor references the last returned row. Existing B chronology and partial unread indexes support the two query paths; no cache/extra schema exists.

Readonly NotificationCriteria/View/Page separate SQL from HTTP. NotificationResource exposes exactly id, stored type string, payload_version, title, body, target {type,id} or null, read_at/null and created_at. UTC dates preserve six fractional digits. Unknown future stored type/version does not invoke enum hydration or interpret payload. The semantic target remains data, with no URL/route resolution. Raw payload, organization/user/membership IDs, relationships, email and credentials are absent. Unread count performs one scoped read_at IS NULL count, returning a nonnegative PHP integer, without cross-organization aggregation or totals.

Mark-read performs one conditional UPDATE by trusted scope/id/read_at IS NULL, followed only on zero changes by an EXISTS query with the identical scope/id. Newly or already read succeeds; absence yields the same 404. read_at uses GREATEST(clock_timestamp(), created_at), keeping the B constraint valid if creation is ahead of the clock. The first timestamp is never overwritten. No multi-write transaction is needed for this atomic conditional write and content-free replay check. A database replay test proves stable time and asserts the conditional update/scoped fallback SQL; no new multiprocess harness was added.

Mark-all is one UPDATE of current-scope unread rows, using the same clock expression. Prior read times and other recipients/organizations/eras stay untouched; zero unread succeeds. It addresses rows visible to that statement, and later inserts can remain unread. No permanent-zero or delivery guarantee is claimed; callers should refetch count. Both mutations preserve content/payload and emit no Audit fact, notification, event or observer.

### Focused security evidence

New C tests pass **109 tests / 675 assertions**; with architecture, the focused run passes **152 tests / 1222 assertions**. Final full-suite JUnit confirms:

| C test file | Passed | Assertions |
| --- | ---: | ---: |
| Unit/Notification/NotificationQueryValidatorTest | 54 | 225 |
| Feature/Notification/NotificationQueryTest | 8 | 100 |
| Feature/Notification/NotificationReadLifecycleTest | 6 | 42 |
| Feature/Notification/NotificationConsumerSecurityTest | 39 | 256 |
| Feature/Notification/NotificationConsumerMembershipEraTest | 2 | 52 |
| Architecture/BoundariesTest (all rules) | 43 | 547 |

Cursor/query coverage includes defaults/min/max, invalid types/sizes, strict base64/JSON/version/date/ULID checks, duplicate/unknown fields, tied three-page traversal without duplicates/omissions, explicit projection/fetch bounds, grouped-OR isolation, foreign tenant/recipient replay, future type/version/UTC representation, unread scope and real sanitized query failure. SQL tests assert all three scope bindings; direct calls use explicit actor even with a different ambient authenticated owner.

HTTP security exercises all four endpoints for guest, active unverified user, active ordinary recipient, another active member, owner recipient/nonrecipient, suspended/removed/nonmember and foreign organization owner. Direct calls to all four deny hidden membership. Hidden tenant/membership wins independently over malformed cursor, invalid page and unsupported key; authorized errors return 422 before any notification SELECT. Item privacy, inconsistent trusted context, safe 503, route inventory/no extra verbs/capabilities/permissions and absence of read-side Audit/new notifications are covered.

Consumer era tests use existing RemoveMembership/SuspendMembership/ActivateMembership unchanged. After removal and new Membership B, old A rows are absent from list/count, item marking returns 404, mark-all leaves all old row content/read state unchanged, and old A cursor can navigate only B fixtures. Direct Application operations enforce the same boundary. Suspension hides all four endpoints; same-ID reactivation restores both read and unread state. New-era/read fixtures use explicit INSERT timestamps and outer rollback, with no historical row deletion or integrity bypass.

### Commands and final validation

Backend DB suites ran sequentially and did not overlap browser writes. Independent frontend quality ran alongside read-only static work/backend tests. Logs/JUnit/browser artifacts stayed under /tmp. Backend quality components were invoked individually to collect JUnit; no dependency lock or installation changed.

| Command | Result |
| --- | --- |
| docker compose up -d --wait | Restored all five existing services, healthy |
| docker compose exec -T backend php vendor/bin/pest tests/Unit/Notification tests/Feature/Notification tests/Integration/NotificationTransactionRequirementTest.php tests/Feature/Audit/AuditHistoryTest.php tests/Unit/Audit/AuditQueryValidatorTest.php tests/Architecture --compact (before edits) | Baseline 288 passed / 1251 assertions |
| docker compose exec -T backend php vendor/bin/pest tests/Unit/Notification/NotificationQueryValidatorTest.php tests/Feature/Notification/NotificationQueryTest.php tests/Feature/Notification/NotificationReadLifecycleTest.php tests/Feature/Notification/NotificationConsumerSecurityTest.php tests/Feature/Notification/NotificationConsumerMembershipEraTest.php tests/Architecture --compact | C + architecture: 152 passed / 1222 assertions |
| docker compose exec -T backend php vendor/bin/pest tests/Unit/Notification/NotificationPayloadValidatorTest.php tests/Unit/Notification/NotificationTextRendererTest.php tests/Feature/Notification/NotificationPersistenceTest.php tests/Feature/Notification/NotificationIntegrityTest.php tests/Feature/Notification/NotificationMembershipEraTest.php tests/Integration/NotificationTransactionRequirementTest.php --compact | Unchanged B regression: 152 passed / 308 assertions |
| docker compose exec -T backend php vendor/bin/pest tests/Feature/Application/OrganizationAccessTest.php tests/Feature/Application/OrganizationWriteAuthorizationTest.php tests/Feature/Audit tests/Unit/Audit tests/Feature/Application/Auditing tests/Feature/InvitationDeliveryTest.php tests/Feature/OrganizationUsersTest.php tests/Integration/AuditTransactionRequirementTest.php tests/Integration/InvitationAuditCommitTest.php tests/Integration/InvitationConcurrencyTest.php --compact | Organization/Audit/invitation regressions: 340 passed / 1810 assertions |
| docker compose exec -T backend composer test -- --compact --log-junit=/tmp/coreerp-notification-c-tests.xml | Complete Pest: 745 passed / 3966 assertions, no failures/skips |
| docker compose exec -T backend composer lint | Pint 211 files passed |
| docker compose exec -T backend composer analyse | Larastan level 8, 143 files, no errors/baseline |
| docker compose exec -T backend composer dump-autoload --optimize --strict-psr --strict-ambiguous | Passed; 9146 classes |
| docker compose exec -T backend composer validate --strict; docker compose exec -T backend composer check-platform-reqs; docker compose exec -T backend composer audit | Valid; all requirements passed (PHP 8.5.11); no advisories |
| docker compose exec -T frontend npm run quality; docker compose exec -T frontend npm audit | All checks/build passed; 61 tests / 10 files; zero vulnerabilities |
| docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -v /tmp/coreerp-notification-c-browser-artifacts:/tmp/coreerp-playwright-results -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test | Complete checked-in suite: 5 passed / 0 failed |
| docker compose config --quiet; docker compose ps | Valid; all five services healthy |
| docker compose exec -T postgres pg_isready -U coreerp -d coreerp; docker compose exec -T redis redis-cli ping | Accepting connections; PONG |
| curl -fsS http://localhost:8088/api/v1/health; curl -fsS http://localhost:8088/api/v1/ready; curl -fsS http://localhost:5174/api/v1/ready; curl -fsS http://localhost:8026/api/v1/info | HTTP 200, API/proxy status ok; Mailpit reachable |
| docker compose exec -T backend php artisan migrate:status | Seven Ran; unchanged B batch 7, no application migration command run in C (existing disposable-database tests migrate their own scratch scope) |
| docker compose exec -T backend php artisan route:list --path=notifications -v | Exactly two GET/HEAD and two POST routes, all authenticated/verified |
| docker compose exec -T postgres psql -U coreerp -d coreerp -Atc "SELECT count(*) FROM organization_notifications" | Zero after backend and normal browser validation |
| docker compose exec -T postgres psql -U coreerp -d postgres -Atc "SELECT count(*) FROM pg_database WHERE datname = 'coreerp_concurrency_test'" | Zero, disposable scope cleaned up |
| git diff --check; git diff -- frontend; git diff -- backend/database/migrations; git diff --cached | Clean; frontend/migration/staged diffs empty |

Additional reads used rg/cat, scoped Pint formatting, JUnit extraction and a final byte comparison against HEAD for B producer files and all seven migrations. Initial test launch found stopped services and ran no tests. One new cursor test initially encoded float 1.0 as JSON integer 1; JSON_PRESERVE_ZERO_FRACTION corrected the fixture, and all final cursor/security/architecture tests passed. No production correctness defect or unresolved validation gate remains.

### Changed files, dependency inventory and limits

Created 27 PHP files: 21 production files under Notification (Contracts NotificationReader/NotificationReadStore; Data NotificationCursor/Criteria/View/Page; Exceptions NotificationQueryInvalid/NotificationNotFound/NotificationStorageFailed; Validation NotificationQueryValidator; Operations ResolveNotificationMembership; Queries ListNotifications/GetUnreadNotificationCount; Commands MarkNotificationRead/MarkAllNotificationsRead; Infrastructure DatabaseNotificationReader/ReadStore; Presentation controller, list request and two Resources). Six test/support files are the five new C test files above and tests/Support/NotificationReadFixtures.php.

Modified nine files: NotificationServiceProvider.php, bootstrap/app.php, routes/api.php, Architecture/BoundariesTest.php, README.md, system-overview.md, ADR 0008, phase-01-core-platform.md and this validation record. No migration was created/edited. B's publisher, access port/context, draft, payload validation, text/vocabulary and Organization adapter/provider remain byte-identical; only Notification's provider adds reader/store bindings. Organization business commands, AcceptInvitation, Identity/Audit source, invitation/credential email, frontend source/tests, dependency manifests/locks, environment and Compose remain unchanged. Browser-created ordinary users/business/Audit/mail data remain normal local test data, while notifications remain empty; no app reset/history deletion/protection bypass is used.

Source dependency inventory is unchanged across modules: Notification → Organization/Identity/Audit has zero imports. OrganizationNotificationAccess imports exactly NotificationOrganizationAccess + NotificationMembershipContext; OrganizationServiceProvider imports NotificationOrganizationAccess. This is three imports/two distinct Notification types in two unchanged Organization files. No business producer edge exists. New architecture rules prohibit consumer Infrastructure calling commands/Presentation, Presentation importing DB/Infrastructure/publishing APIs, reader writes/offsets/joins, and read-store mutations beyond explicit read_at. Existing precise adapter exceptions and framework/domain rules remain intact.

Review mandatory triple predicates including replay EXISTS and grouped cursor OR, membership resolution before validation, item response uniformity, first-read conditional update and creation-time clamp, deliberate projections/future-version tolerance, SQL failure sanitization, read-all statement visibility and narrow dependencies. Queries are live pagination rather than a snapshot. Already-authorized operations can finish during concurrent membership revocation, consistent with existing access semantics; no new linearization/locking policy is claimed. Real replay/conditional SQL is tested; no new multi-process consumer test was required. Production throttling, representative-volume plans, least privilege, retention and deployment hardening remain deferred. All requested local checks were verified; hosted CI was not run.

C adds no producer, frontend center/badge/polling/navigation, generic mail, queues/Horizon, retries/outbox, realtime/Reverb, preferences, deletion or archive. Phase 1.6 remains incomplete. Recommendation after C review and explicit authorization: **1.6D — Invitation Acceptance Notification Producer**, using the unchanged B publisher in AcceptInvitation's existing transaction with trusted current owner recipient context and rollback/credential-exclusion tests. D has not begun; stop at C.
