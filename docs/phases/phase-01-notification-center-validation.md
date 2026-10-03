# Phase 1.6 — Notification Center validation

Current status: B approved/committed as 1627633; C approved/committed as 45ceea9; D approved/committed as dda6cc3; E committed as 32b301d. Independent F review returned **CHANGES REQUIRED**, with exactly one closure-blocking finding: **MEDIUM R1 — Notification backend test isolation**. The narrowly scoped F-Fix correction and its application-database validation are recorded below; Phase 1.6 awaits final read-only re-review. B/C/D/E sections preserve their original checkpoint evidence, including E's superseded disposable-database workaround.

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


## Checkpoint D: Invitation Acceptance Notification Producer

Date: 2026-10-03. Status: **complete locally, awaiting review**. Branch was exactly feature/notifications, the tracked/untracked tree was clean and the approved C checkpoint was HEAD 45ceea9 before editing. Inspected AGENTS.md, ADRs 0005–0008, Phase 1.4/1.5/1.6 validation, acceptance/Audit projections, B publisher/draft/validator/renderer/access bridge, ownership constraints, invitation mail/F1/security, C consumer tests, architecture and guarded disposable concurrency support. The existing five services were already healthy. Baseline passed **353 tests / 1997 assertions**. No staging, commit, push, switch, reset or discard occurred.

### Production scope and transaction contract

Only AcceptInvitation and the new Organization/Application/Notifications/OrganizationNotifications factory change production code. The instance factory follows OrganizationAuditEntries and accepts explicit trusted scalar IDs only: organization, current owner, invitation, newly created invitee membership and accepted user. It returns the existing NotificationDraft with type organization.invitation_accepted, version 1, exactly invitation_id (ULID string), membership_id (canonical positive decimal string) and accepted_user_id (integer), plus organization.users/null-ID target. No model, arbitrary HTTP array, actor authorization, DB query, email, token/hash, URL, role list, text, time or recipient membership input enters the factory. IDs need no new SensitiveParameter labels; existing acceptance email/token and publisher draft protections stay intact.

AcceptInvitation adds only NotificationPublisher and OrganizationNotifications injection. Recipient is owner_user_id from the authoritative organization already locked FOR UPDATE, independent of inviter, accepting actor, roles and client input. The unchanged B publisher resolves that owner's fresh ACTIVE membership and persists its trusted recipient_membership_id; there is no owner bypass. That era is distinct from payload.membership_id, which describes the invitee's newly created membership. B/C schema, vocabulary, validation, access adapter and persistence remain byte-identical.

Inside the existing default PostgreSQL transaction: organization lock → invitation lock/credential and verified identity/state checks → active membership and grants → accepted state → existing invitation.accepted Audit fact → required owner notification → commit. Audit projection/action/payload/actor/subject/role IDs are unchanged. Audit and Notification do not call or import each other; Organization coordinates their public capabilities. Publication is synchronous, before commit, with no second transaction, afterCommit, catch-and-continue, queue, mail or observer. Failure propagates and restores the pending invitation, membership/grants, Audit and any notification insertion. Existing Audit failure stops before publication and still rolls back.

### Producer, security and atomicity evidence

Focused producer run passes **11 tests / 192 assertions** (10 new tests plus the extended existing concurrency case). Final complete-suite JUnit partitions:

| Test file | Passed | Assertions |
| --- | ---: | ---: |
| Unit/Organization/OrganizationNotificationsTest | 2 | 20 |
| Feature/Application/Notifications/InvitationNotificationIntegrationTest | 7 | 117 |
| Integration/InvitationNotificationAtomicityTest | 1 | 21 |
| Integration/InvitationConcurrencyTest | 1 | 34 |
| Architecture/BoundariesTest | 46 | 576 |
| Feature/Application/Auditing/InvitationAuditIntegrationTest | 24 | 243 |
| Feature/InvitationDeliveryTest (unchanged F1) | 6 | 53 |

Success assertions inspect all 13 persisted columns: generated ULID, correct organization/owner/actual owner membership era, exact type/version/three-field payload, title Invitation accepted, body User #<accepted ID> accepted an invitation and joined the organization., semantic target/null ID, unread state and creation inside a database-clock interval. SQL capture proves accepted-state UPDATE precedes real Audit INSERT and Notification INSERT, and the publisher INSERT supplies neither created_at nor read_at. An invitation with two actual role grants assigns both; the unchanged Audit fact contains sorted role IDs while Notification contains none. Direct invocation works with an unrelated ambient authenticated actor.

A valid delegated members.invite member issues a roleless invitation to a third user; real HTTP acceptance addresses only the distinct persisted owner, regardless of forged recipient/era/organization/read timestamp fields. Scans of actual payload/title/body/target exclude plaintext token, hash, invitation/accepted/owner/inviter emails and the credential invitation URL. Boolean checks avoid dumping sensitive actual values. A failing test-only publisher proves the command propagates required failure after accepted state, grants and real Audit insertion, then restores the full persisted pre-command invitation and removes acceptance membership/grants/history. With zend.exception_ignore_args=0 the fixed publication failure has no previous exception and no token/hash/email trace.

The physical atomicity test uses the existing guarded disposable database, with no outer Feature transaction. A narrow test-only NotificationPublisher decorator verifies transaction level 1/PDO in transaction, accepted state, membership/grant and real Audit row, delegates to the real publisher, observes the real notification, then throws before transaction completion. After physical rollback: level 0/PDO out of transaction, no accepted membership/grant/Audit/notification, exact persisted pending invitation restored, invitation role grant retained and prior organization.created history retained. No production failure toggle or integrity bypass exists.

Genuine concurrency retains the existing two independent processes/connections and observes both waiting on PostgreSQL locks before releasing the parent. Results remain accepted and rejected:accepted, one membership, accepted invitation and one exact invitation.accepted Audit fact. The winning real acceptance creates exactly one notification (sole organization row), with asserted organization, owner recipient, owner's actual era, invitation/resulting membership/accepted user IDs, exact type/version/copy/target/unread/time. Workers/helper/guard configuration are unchanged. Whole-database teardown removes committed fixtures; no notification-row cleanup or disabled constraints. The reserved disposable database count is zero after all tests.

HTTP and direct replay leave the original sole notification byte-for-byte unchanged, retaining existing 422 accepted rejection and no deduplication key. C owner list returns one exact safe resource with no payload or scope IDs; count rises 0→1. The invitee's list/count remain empty/0 and owner item marking returns fixed 404 without marking it read. C's existing read lifecycle remains fully covered.

Existing failure tests gain no-notification assertions for wrong identity, unverified identity, invalid token, expiry, revocation, accepted state, active/suspended duplicate member, tenant mismatch and spoofed/unknown credentials. New token Form Request and real acceptance grant failure tests prove no publication/Audit. Existing late state and Audit failures assert no notification. Existing issuance/reinvite tests assert credential mail and Audit behavior without Notification; a delegated issuance also asserts no notification. Acceptance sends no new mail. InvitationDelivery, SMTP transport selection/security and credential-bearing email behavior are unchanged. No other producer exists.

### Commands and complete validation

Commands run from the repository root. Backend DB suites were sequential and finished before browser writes. Frontend quality/static/read-only gates could run independently. Logs, JUnit and browser artifacts are under /tmp/coreerp-notification-d-*; no repository dependency/configuration/install change. Backend quality components were invoked individually to collect full-suite JUnit.

| Command | Result |
| --- | --- |
| `docker compose exec -T backend php vendor/bin/pest tests/Unit/Notification tests/Feature/Notification tests/Integration/NotificationTransactionRequirementTest.php tests/Feature/Application/Auditing/InvitationAuditIntegrationTest.php tests/Feature/InvitationDeliveryTest.php tests/Feature/OrganizationUsersTest.php tests/Integration/InvitationConcurrencyTest.php tests/Architecture --compact` (before edits) | Baseline 353 passed / 1997 assertions |
| `docker compose exec -T backend php vendor/bin/pest tests/Unit/Organization/OrganizationNotificationsTest.php tests/Feature/Application/Notifications/InvitationNotificationIntegrationTest.php tests/Integration/InvitationNotificationAtomicityTest.php tests/Integration/InvitationConcurrencyTest.php --compact` | Focused producer 11 passed / 192 assertions |
| `docker compose exec -T backend php vendor/bin/pest tests/Unit/Notification tests/Feature/Notification tests/Integration/NotificationTransactionRequirementTest.php --compact` | Complete unchanged B/C regression 261 passed / 983 assertions: B 152/308, C 109/675 |
| `docker compose exec -T backend php vendor/bin/pest tests/Feature/Application tests/Feature/Audit tests/Unit/Audit tests/Unit/Organization tests/Feature/OrganizationUsersTest.php tests/Feature/OrganizationUsersIntegrityTest.php tests/Feature/InvitationDeliveryTest.php tests/Feature/RbacTest.php tests/Integration/AuditTransactionRequirementTest.php tests/Integration/InvitationAuditCommitTest.php tests/Integration/InvitationConcurrencyTest.php tests/Integration/InvitationNotificationAtomicityTest.php tests/Integration/DisposableConcurrencyDatabaseTest.php tests/Unit/DisposableConcurrencyDatabaseTest.php tests/Architecture --compact` | Business/authorization/RBAC/Audit/invitation/F1/concurrency/architecture regression 458 passed / 2860 assertions |
| `docker compose exec -T backend composer test -- --compact --log-junit=/tmp/coreerp-notification-d-tests.xml` | Complete Pest 758 passed / 4190 assertions; zero failures/errors/skips |
| `docker compose exec -T backend php vendor/bin/pint app/Modules/Organization/Application/Commands/AcceptInvitation.php app/Modules/Organization/Application/Notifications tests/Unit/Organization/OrganizationNotificationsTest.php tests/Feature/Application/Notifications tests/Integration/InvitationNotificationAtomicityTest.php tests/Integration/InvitationConcurrencyTest.php tests/Architecture/BoundariesTest.php tests/Feature/Application/Auditing/InvitationAuditIntegrationTest.php tests/Feature/Application/MembershipLifecycleTest.php tests/Feature/OrganizationUsersTest.php` | Scoped formatting: 10 files passed |
| `docker compose exec -T backend composer lint` | Complete read-only Pint: 215 files passed |
| `docker compose exec -T backend composer analyse` | Larastan level 8: 144 files, no errors/baseline |
| `docker compose exec -T backend composer dump-autoload --optimize --strict-psr --strict-ambiguous` | Passed: 9147 classes |
| `docker compose exec -T backend composer validate --strict` | Valid |
| `docker compose exec -T backend composer check-platform-reqs` | All requirements passed, PHP 8.5.11 |
| `docker compose exec -T backend composer audit` | No advisories |
| `docker compose exec -T frontend npm run quality` | ESLint/Prettier/TypeScript, 61 Vitest tests / 10 files and build (125 modules) passed |
| `docker compose exec -T frontend npm audit` | Zero vulnerabilities |
| `docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -v /tmp/coreerp-notification-d-browser-artifacts:/tmp/coreerp-playwright-results -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test` | Complete checked-in suite: 5 passed / 0 failed, 14.8s |
| `docker compose config --quiet`; `docker compose ps` | Valid; all five services healthy |
| `docker compose exec -T postgres pg_isready -U coreerp -d coreerp`; `docker compose exec -T redis redis-cli ping` | Accepting connections; PONG |
| `curl -fsS http://localhost:8088/api/v1/health`; `curl -fsS http://localhost:8088/api/v1/ready`; `curl -fsS http://localhost:5174/api/v1/ready`; `curl -fsS -o /tmp/coreerp-notification-d-mailpit-info.json -w '%{http_code}' http://localhost:8026/api/v1/info` | HTTP 200; health/API/proxy status ok; Mailpit reachable |
| `docker compose exec -T backend php artisan migrate:status` | All seven Ran, batches 1–7; no new or edited migration, no application migration command |
| `docker compose exec -T postgres psql -U coreerp -d postgres -Atc "SELECT count(*) FROM pg_database WHERE datname = 'coreerp_concurrency_test'"` | Zero: disposable scope absent |
| `docker compose exec -T postgres psql -U coreerp -d coreerp -Atc "SELECT count(*) FROM organization_notifications"` | One ordinary row from the passing real browser acceptance flow, retained normally |
| `git diff --check`; `git diff -- frontend`; `git diff -- backend/database/migrations`; `git diff --cached` | Clean whitespace; frontend/migration/staged diffs empty |

Additional inspection used rg, cat/sed, git status/branch/log/diff, JUnit XML extraction and a final Python byte comparison/inventory. The initial expanded focused run passed production/concurrency checks but two rollback fixtures compared transient Eloquent timestamps/order with PostgreSQL's stored timezone/order. Capturing the fresh persisted pre-command row and using a boolean comparison fixed only these assertions; final producer/full suites pass. No production schema mismatch or unresolved gate remains. No hosted CI/deployment or production-volume test was requested or run.

### Files, dependency inventory and manual review

Created four files:

- backend/app/Modules/Organization/Application/Notifications/OrganizationNotifications.php
- backend/tests/Unit/Organization/OrganizationNotificationsTest.php
- backend/tests/Feature/Application/Notifications/InvitationNotificationIntegrationTest.php
- backend/tests/Integration/InvitationNotificationAtomicityTest.php

Modified eleven files: AcceptInvitation.php; Architecture/BoundariesTest.php; Feature/Application/Auditing/InvitationAuditIntegrationTest.php; Feature/Application/MembershipLifecycleTest.php; Feature/OrganizationUsersTest.php; Integration/InvitationConcurrencyTest.php; README.md; docs/architecture/system-overview.md; docs/decisions/0008-notification-center-architecture.md; docs/phases/phase-01-core-platform.md; and this validation record.

Final source edges are **eight imports / seven distinct Notification types / four Organization files**, including **five producer imports / five distinct public types / two Application files**:

| Organization source | Exact Notification Application imports |
| --- | --- |
| Application/Commands/AcceptInvitation | Contracts\NotificationPublisher |
| Application/Notifications/OrganizationNotifications | Data\NotificationDraft, Data\NotificationTarget, Vocabulary\NotificationType, Vocabulary\NotificationTargetType |
| Infrastructure/Notification/OrganizationNotificationAccess (unchanged) | Contracts\NotificationOrganizationAccess, Data\NotificationMembershipContext |
| Infrastructure/Providers/OrganizationServiceProvider (unchanged) | Contracts\NotificationOrganizationAccess |

Architecture now allows only the two named Application producer classes, restricts each to its exact types and keeps the factory framework/model-free. All other Organization classes retain the Notification prohibition except the unchanged named access bridge. No consumer/query/read contracts, validators, persistence ports or Infrastructure/Presentation implementation become producer dependencies. Domain has no Notification edge; Notification imports no Organization/Identity/Audit; Audit and Identity have no Notification edge. No other module gets a producer exception.

Final scope comparison proves frontend (including tests), all seven migrations, complete Notification B/C source, Identity/Audit source, Organization access bridge/provider, InvitationDelivery/Mailable, OrganizationAuditEntries and disposable helper/worker are byte-identical to C HEAD. Routes, controllers, requests, resources, Domain/authorization, manifests/locks, environment and Compose are untouched. Browser-created users/business/history/mail and the new ordinary notification remain normal local data; no row cleanup, trigger/FK disabling or app database reset occurred.

Review the locked persisted owner selection, payload membership versus recipient era, unchanged Audit projection, ordering/uncaught required failure, database-clock defaults, narrow import allowlists and test-only post-insert decorator. The existing coarse organization lock remains appropriate for present administrative scale; no new ownership-transfer, broken-owner workflow, producer dedup/retry guarantee or consumer revocation linearization is introduced. Live pagination/read-all statement visibility, privileged SQL threat model, retention/least privilege/read throttling/production plans/deployment hardening remain the B/C limits. An interrupted disposable run can leave its fixed database; existing guards refuse reuse/drop and require verified recovery. All requested local gates were verified.

D is complete locally and stops here. Phase 1.6 remains incomplete. Recommendation after review/commit and explicit authorization: **1.6E — Notification Center UI & Browser Flow**, consuming C with safe plain-text rendering, tenant/era state reset, list/count/read controls, semantic target authorization and browser coverage of the real invitation-acceptance owner notification. No E work, other producers, generic notification email, jobs/queues/Horizon, retry/outbox, polling frontend or realtime/Reverb is included.


## Checkpoint E: Notification Center UI & Browser Flow

Date: 2026-10-03. Status: **1.6E complete locally, awaiting review. Phase 1.6 is incomplete; independent 1.6F review is pending and has not begun.** D is the approved committed baseline dda6cc3. Only E frontend/UI/browser work was authorized. No backend defect or required API change was found, and no other producer, generic notification email, queue/Horizon, realtime/Echo/Reverb, preferences or final independent review was added.

### Preflight and frontend architecture

Preflight passed before editing: branch exactly feature/notifications, clean tracked/untracked working tree, HEAD dda6cc3 (feat: notify owner when invitation is accepted). Read AGENTS.md, ADR 0008 and B/C/D validation; inspected the existing router/App shell, route organization context, Users/Audit views and API clients, generation/error/accessibility conventions, notification resources/read actions, actual AcceptInvitation publication, existing invitation acceptance Playwright test and Mailpit helpers. Baseline frontend quality passed ESLint/Prettier/TypeScript, 61 tests in 10 files and production build (125 modules). No stage, commit, push, branch switch, reset or discard occurred.

The repository uses App.vue and flat authenticated/verified routes. App now provides one mounted useOrganizationNotifications composable driven only by the current organization route. NotificationBell is a native keyboard-operable button with real-count accessible label, no zero badge and visual 99+ cap. The workspace includes an ordinary Notifications link alongside unchanged Users/Roles/Audit destinations; wrapping navigation preserves small-screen behavior and existing Audit capability logic. No notification permission/capability or role-name check exists.

Notification API types contain only id/type/payload_version/title/body/target/read_at/created_at and list cursor metadata. The semantic client encodes organization/item IDs, requests per_page=25 and passes only the opaque server cursor; it uses exactly the four existing C endpoints. There is no raw payload, recipient/era/tenant field, generic request method, filter, identity lookup, extra endpoint or frontend Audit coupling.

Shell state shares only current organization/count/loading/feedback and epoch. Page-local rows/cursor/read confirmations are never cached globally, placed in Pinia or persisted in browser storage. Count refresh runs on visible context entry, focus/visibility restoration, successful explicit reads and manual Refresh. The 60-second timer skips hidden documents and concurrent requests. Context change/unmount removes listeners/timers and aborts/increments request serials. Manual/mutation count refresh supersedes older work; guards cover success, failure and finally. Automatic polling never fetches history.

The dedicated /app/organizations/:organizationId/notifications page shows organization context, newest-first stored snapshots, UTC time, Read/Unread text, Refresh, Mark all as read, Mark as read, Load older and clear loading/empty/error/end states. It does not mark on opening. Mark-one waits for 204 then records a deliberate local read confirmation without fabricating read_at or changing list order/cursor, and refetches authoritative count. Refresh subsequently retrieves server timestamps. Mark-all refetches first page/count; a later committed unread row remains reflected. Refresh resets rows/cursor and retrieves the first page. Continuation appends server order using next_cursor, blocks concurrent calls and performs simple ID deduplication without generating cursors/totals.

Synchronous scope epochs and page generations clear every prior organization state/target and reject late list/count/read-one/read-all/continuation/context successes and failures. Current-scope 401/403/404 clears rows/count/cursor/loading/errors/read confirmations and stops automatic polling. It displays existing normalized feedback and an organization navigation link; only explicit Refresh or a context change rechecks access. Generic count 5xx/network failures retain safe loaded content and use quiet status feedback. Existing auth/routing and backend membership-era scope remain authoritative; no custom session or membership reconstruction occurs.

Snapshots render through ordinary Vue escaping, never v-html. Only organization.invitation_accepted/version 1 with exactly organization.users/null-ID target maps to the current organization-users named route. Unknown type/version, unsupported target, URL string, unexpected keys and nonnull ID show safe text without View. No accepted-user name/email request exists. Times parse explicit API UTC and use Intl timeZone=UTC; the time element retains exact API datetime/title. Native buttons/links, headings, ordered-list/articles, status/alert messages, disabled feedback and explicit non-color read labels preserve existing focus conventions.

### Automated and real browser evidence

Two new frontend unit files contain **29 tests**: 26 component/behavior tests and 3 client/target/time tests. The existing route-guard test now covers both Audit and Notifications with guest, unverified and verified actors. The final focused run passes **35 tests / 3 files**; complete Vitest passes **91 tests / 12 files**.

Behavior tests cover initial/no-auto-read/empty/error/end states, zero/positive/99+ badges and native bell navigation; one/all 204 read flows with no timestamp fabrication; authoritative later-unread count after read-all; opaque pagination/order/dedup/double-click/Refresh reset; initial/older/mutation failures; every 401/403/404 path; ordinary background 503 retention; stale tenant and superseded refresh count/list responses; stale read-one/read-all/continuation success and failure; escaped HTML-like title/body; unknown formats/targets; UTC equivalence and invalid time fallback. Fake-clock tests cover 60-second polling, hidden entry/tab behavior, visibility/focus refresh, overlap prevention, route exit and unmount cleanup without a new page request on teardown.

One new Playwright test uses normal registration/verification, organization creation, roleless invitation through SMTP/Mailpit, invited-user registration/verification and actual acceptance UI. The notification is produced by **AcceptInvitation → NotificationPublisher**; no direct organization_notifications fixture or database write is used. Refocus refreshes the owner's badge to one without a 60-second wait. Native bell keyboard Space opens history; the exact stored Invitation accepted title/body appears Unread. The test asserts all eight public resource keys and checks displayed content for absence of token/emails/invitation URL/raw payload/recipient-era fields without logging those values. Explicit Mark as read refreshes badge to zero; reload retains Read and removes the action. Keyboard Enter on View opens the same organization's authorized Users page.

The real invitee's collection/count are []/0; an authenticated CSRF-valid POST attempting to mark the owner's notification returns 404. The same owner creates Organization B, whose badge is zero and page is empty with no A row, then returns to A and sees its read history. Backend regression separately covers foreign tenants and historical membership-era denial. Shared Mailpit/register/verify helpers were extracted from the existing Users browser test without changing its workflow.

Final targeted browser run: **1 passed / 0 failed, 10.1s**. Final complete browser run: **6 passed / 0 failed, 17.0s**, including existing auth/logout, organization isolation, roles, Users/invitation lifecycle and Audit filter/pagination/refresh flows. No seeded notification or polling delay is used.

### Backend regression database boundary

An initial focused/full run against the configured development database encountered 11 existing Notification persistence-test failures: those rollback-protected fixtures assume the entire notification table starts empty, while D's successful browser flow had already retained an ordinary notification. Constraint-alteration fixtures also validate those existing rows. This was a test database precondition, not a blocking notification API defect. No retained application row was deleted, no backend test or production code was edited, and no constraint/trigger was disabled to make the run pass.

The rerun used a temporary out-of-repository runner /tmp/coreerp-notification-e-validation.py and fresh coreerp_notification_e_validation. The runner asserts that its fixed target is neither coreerp, postgres nor the existing concurrency target, refuses any pre-existing target, records ownership only after its own CREATE DATABASE and bootstraps Laravel to verify APP_ENV=testing, default driver pgsql and the actual connected database name before migration. Existing seven migrations run only in this newly owned validation database. Focused/full Pest execute sequentially with explicit environment overrides; the runner's finally block drops only the target it created. The existing guarded coreerp_concurrency_test helper remains unchanged and uses its separate target for physical commit/concurrency tests. All backend database suites finished before the E browser reruns. Final pg_database inspection finds neither disposable target.

Final focused backend run: **318 passed / 1751 assertions**, comprising unchanged B/C Notification security/persistence/query/read coverage (261/983), D producer/atomicity/real acceptance concurrency (11/192) and architecture (46/576). Final complete Pest: **758 passed / 4190 assertions**, with zero failures/errors/skips. Complete-suite JUnit confirms Architecture/BoundariesTest 46/576, InvitationConcurrencyTest 1/34, InvitationNotificationAtomicityTest 1/21 and InvitationAuditCommitTest 3/32. This preserves the real Business + Audit + Notification transaction evidence without changing backend semantics.

### Commands and final local gates

Commands run from the repository root. Logs/JUnit/browser artifacts are under /tmp/coreerp-notification-e-*. Backend database commands below execute only inside the guarded fresh validation-database runner described above; they are not application-database migration/reset instructions.

| Command | Result |
| --- | --- |
| `docker compose exec -T frontend npm test -- src/__tests__/Notifications.test.ts src/__tests__/NotificationsClient.test.ts src/__tests__/AuthRouting.test.ts` | Final focused: 35 passed / 3 files |
| `docker compose exec -T frontend npm run quality` | ESLint, Prettier, TypeScript, 91 Vitest tests / 12 files and production build (131 modules) passed |
| `docker compose exec -T frontend npm run lint`; `docker compose exec -T frontend npx prettier --check e2e/notifications.spec.ts` | Final browser assertion adjustment also passed lint/format |
| `docker compose exec -T frontend npm audit` | Zero vulnerabilities |
| `docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -v /tmp/coreerp-notification-e-browser-artifacts:/tmp/coreerp-playwright-results -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test e2e/notifications.spec.ts` | 1 passed / 0 failed, 10.1s |
| Same browser command with `npx playwright test` | Complete suite: 6 passed / 0 failed, 17.0s |
| `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=coreerp_notification_e_validation -e DB_URL= backend php artisan migrate --force --no-interaction` | Seven existing migrations applied only after fresh-database ownership and actual-connection guard |
| `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=coreerp_notification_e_validation -e DB_URL= backend php vendor/bin/pest tests/Unit/Notification tests/Feature/Notification tests/Integration/NotificationTransactionRequirementTest.php tests/Unit/Organization/OrganizationNotificationsTest.php tests/Feature/Application/Notifications/InvitationNotificationIntegrationTest.php tests/Integration/InvitationNotificationAtomicityTest.php tests/Integration/InvitationConcurrencyTest.php tests/Architecture --compact` | 318 passed / 1751 assertions |
| `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=coreerp_notification_e_validation -e DB_URL= backend composer test -- --compact --log-junit=/tmp/coreerp-notification-e-tests.xml` | 758 passed / 4190 assertions; zero failures/errors/skips |
| `docker compose exec -T backend composer lint` | Read-only Pint: 215 files passed |
| `docker compose exec -T backend composer analyse` | Larastan level 8: 144 files, no errors/baseline |
| `docker compose exec -T backend composer validate --strict` | Valid |
| `docker compose exec -T backend composer check-platform-reqs` | All requirements passed; PHP 8.5.11 |
| `docker compose exec -T backend composer audit` | No advisories |
| `docker compose config --quiet`; `docker compose ps` | Valid; all five services healthy |
| `docker compose exec -T postgres pg_isready -U coreerp -d coreerp`; `docker compose exec -T redis redis-cli ping` | Accepting connections; PONG |
| `curl -fsS http://localhost:8088/api/v1/health`; `curl -fsS http://localhost:8088/api/v1/ready`; `curl -fsS http://localhost:5174/api/v1/ready`; `curl -fsS -o /tmp/coreerp-notification-e-mailpit-info.json -w '%{http_code}' http://localhost:8026/api/v1/info` | API liveness/readiness and frontend proxy status ok; Mailpit 200 |
| `docker compose exec -T backend php artisan migrate:status` | All seven Ran; no new/edited migration and no application database migration/reset |
| `docker compose exec -T postgres psql -U coreerp -d postgres -Atc "SELECT count(*) FROM pg_database WHERE datname IN ('coreerp_concurrency_test', 'coreerp_notification_e_validation')"` | Zero: both disposable scopes absent |
| `git diff --check`; `git diff -- backend`; `git diff -- backend/app`; `git diff -- backend/database/migrations`; `git diff --cached` | Clean whitespace; all backend/production/migration/staged diffs empty |

No dependency, environment, Compose, CI, backend routing or migration changes. No production-volume/deployment/hosted CI execution was requested or performed. All requested local gates were verified.

### File inventory, visual checks and review handoff

Created eight frontend files:

- frontend/src/lib/notifications.ts
- frontend/src/composables/useOrganizationNotifications.ts
- frontend/src/components/NotificationBell.vue
- frontend/src/views/OrganizationNotificationsView.vue
- frontend/src/__tests__/NotificationsClient.test.ts
- frontend/src/__tests__/Notifications.test.ts
- frontend/e2e/notifications.spec.ts
- frontend/e2e/support/invitations.ts

Modified five frontend files: App.vue, router/index.ts, views/OrganizationWorkspaceView.vue, __tests__/AuthRouting.test.ts and e2e/organization-users.spec.ts. Modified five documents: README.md, this validation record, ADR 0008, system overview and Phase 1 roadmap. Existing Audit view/client and all 240 tracked backend files are byte-identical to dda6cc3, including backend tests and all seven migrations. Backend/frontend manifests/locks, Compose and CI are unchanged. New route follows existing guards; no Notification → Audit frontend import or generic activity-center abstraction exists. Git remains feature/notifications at dda6cc3 with an empty index; E is unstaged/uncommitted.

Viewed the final real-browser Notification Center screenshots at desktop 1280×806 and mobile 390px (844px viewport, full-page capture). Header/actions/cards/body/time/View/read badge wrap cleanly; the mobile document width does not exceed innerWidth. Existing native focus styling is retained. Browser keyboard checks cover native bell Space navigation, Tab reaching an interactive element and View Enter navigation. Artifacts: /tmp/coreerp-notification-e-browser-artifacts/notification-desktop.png and notification-mobile.png. Long malicious/HTML-like and unknown content is covered by escaped component tests; older/loading/error variants are covered behaviorally rather than seeded into the real browser producer flow.

Review the shell route-derived scope, epoch/request/generation guards on both outcomes, denied-scope stop/reset behavior, explicit read confirmation after 204, statement-scoped read-all refetch, opaque continuation lock, finite target mapping and actual producer browser assertions. Review helper extraction and the wrapping workspace navigation with Audit capability unchanged. These are the E implementation handoff points, not an independent F review.

Known limits: count polling is foreground-only and approximate, with focus/visibility refresh; history refresh is manual and never automatically reordered. Access changes clear content when the next authorized operation reports denial; hidden tabs skip polls until visible. Mark-one does not display a fabricated read timestamp, and read-all resets loaded history to the first page. Loaded older rows accumulate only in the mounted page until Refresh/context exit. No filters, global history/count, realtime, extra producer, delivery preferences or generic notification email exists. Existing B/C retention, privileged SQL and deployment/read-scale limits remain. Regression runs require a fresh guarded database because existing persistence fixtures assume an empty notification table; application browser history is retained normally.

**E stops here.** Recommendation after E review/checkpoint commit and explicit authorization: **Phase 1.6F — Final Independent Security & Code Review**. F has not begun; Phase 1.6 is not marked complete.

## Phase 1.6F-Fix: Notification Test Isolation

Date: 2026-10-03. Baseline verified before edits: branch `feature/notifications`, completely clean tracked/untracked working tree, HEAD `32b301d` (`feat: add organization notification center`). Inspected git log, AGENTS.md, ADR 0005, this validation record, the user-supplied independent final review finding R1, notification production persistence/schema, all notification test references, support fixtures, Pest/phpunit configuration, Composer/frontend scripts and checked-in browser/CI commands. No branch change, staging, commit, push, reset or discard occurred.

The independent review found no BLOCKER/HIGH production defect. R1 reproduced 11 failing / 4 passing persistence tests after browser notifications remained committed; a retained READ row also prevented installing the read-failure constraint. E's guarded disposable validation proved production behavior but did not repair ordinary `composer quality`. This correction supersedes E's test-isolation limitation and workaround. No validation-database runner or default database override is used here.

### Root cause and complete assertion inventory

Feature tests use `DatabaseTransactions` on the configured PostgreSQL application database; rollback isolates their writes but does not hide earlier committed browser data. Whole-table emptiness is not a Notification invariant. An assertion must identify the organization, recipient or row for which the operation is responsible. PostgreSQL also validates a new CHECK against existing rows during `ALTER TABLE`; globally impossible or unread-only predicates reject legitimate unrelated history before exercising the intended storage failure.

| Test/assertion found at baseline | Correction |
| --- | --- |
| NotificationPersistenceTest: suspended / removed / nonmember / cross-tenant recipient rejection (four cases, baseline line 108) | Absence for the exact attempted organization and recipient |
| NotificationPersistenceTest: inconsistent tenant / user / membership adapter context (three cases, baseline line 131) | Absence for the newly generated fixture organization |
| NotificationPersistenceTest: unsafe payload rollback (baseline line 155) | Absence for the fixture organization; earlier organization write still rolls back |
| NotificationPersistenceTest: rendering failure rollback (baseline line 178) | Absence for the fixture organization; safe exception and rollback assertions retained |
| NotificationPersistenceTest: real insert failure (baseline line 209) | Absence for the fixture organization; real SQL failure, category, sanitized trace and caller rollback assertions retained |
| NotificationPersistenceTest: organization creation adds no producer (baseline line 214) | Capture the returned new organization and assert absence for its ID |
| NotificationPersistenceTest: global `CHECK (false)` | Organization-specific failure fixture described below |
| NotificationReadLifecycleTest: global `CHECK (read_at IS NULL)` | Notification-ID-specific failure fixture described below; compare the entire owned row after failure |
| NotificationIntegrityTest: global zero after migration down/up | Assert the specifically inserted fixture notification is absent after reconstruction; schema/index/constraint checks remain |
| NotificationConsumerSecurityTest: global before/after notification count | Compare counts only for its uniquely owned organization |
| NotificationTransactionRequirementTest: global before/after notification count | Use a newly generated draft organization ID and assert absence in that attempted scope |

The last two count comparisons did not require initial emptiness, and the migration test owned its reconstructed table inside an outer transaction; they were nevertheless made explicit about test-owned scope. Existing owner-ineligibility, successful-publication rollback, query/API and invitation-producer tests already used organization/recipient/ID predicates. NotificationQueryTest's temporary column rename does not validate retained row values and already rolls back its DDL. No remaining unscoped `organization_notifications` count/emptiness assertion, global insert/read failure predicate or other retained-row precondition was found in the repository tests. Metadata counts against `pg_indexes` are schema assertions, not notification-row assumptions.

### Scoped real PostgreSQL failures and regression coverage

Insert failure before: `CHECK (false)`.

Insert failure after: `CHECK (organization_id <> <SQL-quoted newly generated fixture organization ID>)`, installed by `NotificationFixtures::rejectInsertsForOrganization`. The owned organization is newly created and has no notifications before installation. Every other organization satisfies the CHECK, including previously committed rows. The attempted real publisher insert violates it and still produces `NotificationWriteFailed` with category `persistence_failed`.

Read failure before: `CHECK (read_at IS NULL)`.

Read failure after: `CHECK (id <> <SQL-quoted newly generated fixture notification ID> OR read_at IS NULL)`, installed by `NotificationReadFixtures::rejectReadForNotification`. Notification ID is the smallest reliable scope because it is the primary key. The owned row starts unread and satisfies installation; unrelated rows automatically satisfy the first term even when already read. Updating only the owned row to a non-null read timestamp violates the CHECK and still produces the safe `NotificationStorageFailed`. The existing deferred-constraint flush around DDL is preserved.

Both helpers use the actual PostgreSQL connection's `escape()` (PDO quoting), fixed constraint names and normal validated CHECKs. No unsafe raw identifier interpolation, `NOT VALID`, disabled constraint, mock-only persistence substitute, production schema change or global cleanup is introduced. Constraints and regression fixtures live inside the existing Feature transaction and disappear through its rollback.

Every NotificationPersistenceTest now seeds a legitimate unrelated unread row through the real publisher in a separately generated organization owned by another user. Its afterEach compares the entire unrelated stored row unchanged. This makes all rejected-publication, payload/render/SQL failure and rollback cases explicitly exercise retained unread history.

New retained-unread test: **rejects only the owned publication while preserving an unrelated retained unread notification**. The unrelated row exists before constraint installation; the owned insert fails with the persistence category and leaves its organization empty. A subsequent real publication in the unrelated organization succeeds under the installed constraint; both unrelated rows remain unread and the first row stays byte-for-byte unchanged in its stored values.

New retained-read test: **rejects the owned read update while preserving an unrelated retained read notification**, with **mark-one** and **mark-all** cases. A legitimate non-null read timestamp in another organization exists before constraint installation. The owned real read operation fails safely; full owned/unrelated rows are unchanged. A different notification in the same owned organization/recipient/era can still be marked read, proving that the constraint rejects only its chosen ID. These three new cases increase the full suite from 758 to 761 tests.

### Real retained-data workflow and preservation evidence

All ordinary Feature tests and normal `composer quality` used the unchanged configured database **coreerp**, PostgreSQL, existing DatabaseTransactions, phpunit configuration and Composer scripts. There is no `backend/.env.testing` and phpunit does not override DB_DATABASE. The app's actual connection was verified as `pgsql` / `coreerp`. Existing genuine commit/concurrency tests continue using their already established guarded disposable concurrency database; their model was not changed or expanded.

Initial committed state: **7 notifications / 3 unread / 4 read**, spanning seven organizations, seven recipient users and seven membership IDs. IDs and read states:

| Notification ID | Initial state |
| --- | --- |
| 01M410F6HC4E6BNJKYSMHD12AZ | Unread |
| 01M415JKAG9PKV16KHVNDY5HSC | Read |
| 01M415MH60SMJYSAH9M0MG18DR | Read |
| 01M415MHNKV7AY9VBZ14HVF2SJ | Unread |
| 01M41661DMEZ404HSBBJB5J2AN | Read |
| 01M4166F7RHGFN25G2KED10JS6 | Unread |
| 01M4166F92FVXFZ19G56RZX1AH | Read |

First, affected tests passed against that existing state: **24 passed / 140 assertions**. Complete Notification and backend quality then passed; canonical JSON snapshots of every stored notification column before and after these backend runs compared identically using `cmp`.

Then the repository's real targeted Playwright notification flow passed (**1 passed**, 11.3s). It registered/verified users, sent/accepted a normal invitation and read the resulting owner notification. Its newly committed READ row was `01M419KFFD203CG52NYM26GHHA`, organization `01m419kdcxqftjq3waj50xrenh`, recipient user `18800`, recipient membership `15395`, read_at `2026-10-03 16:29:53.034574+00`. The resulting state was **8 / 3 unread / 5 read**. Against exactly that same database, affected tests again passed **24 / 140**, followed by normal `composer quality`: **761 / 4222**, Pint and Larastan all passed. The eight-row canonical snapshots before/after backend quality also compared identically. Both **backend quality → Playwright** and **Playwright → affected tests → backend quality** passed.

Complete checked-in Playwright then passed **6 / 0 failed**, 18.1s. It added READ notification `01M419T7P44NV5NVSGGMWWB5V4` (organization `01m419t450vza25np1tt7wgh15`, user `19612`, membership `16114`, read_at `2026-10-03 16:33:34.829532+00`) and UNREAD notification `01M419T8JK974KGVF8EFVFJP2P` (organization `01m419t4aqjs2kajyz27jgjysf`, user `19613`, membership `16115`). Final committed state: **10 / 4 unread / 6 read**. Complete Notification coverage passed again in randomized test order with seed **1606**: **275 / 1207** against those ten retained rows. Its before/after canonical snapshots were identical. An ID-keyed comparison of every initial row's complete stored values against the final snapshot confirmed all seven initial rows were unchanged; only the three expected browser rows were added.

Canonical JSON SHA-256 evidence: initial/before-browser `68b0f7789e32657b2a23999d1d1439af7b9c94421b0356207fc9da4e3a704df1`; after-targeted-browser/after-quality `0c1e2c9ec95d8051a8b8856565a0654be5980c2433b61fd253d95a17ef52e106`; after-complete-browser/final `02b2964c2cbbe93f5295e84216f8f62f0f4371d395cb66b5bcdae5ca0cbf3d53`. No legitimate row was deleted, truncated or reset, and no database was cleared to make a test pass. The existing migration mechanics test temporarily exercises down/up inside its rollback transaction; it is not application-data cleanup, and the canonical snapshots prove committed rows are restored unchanged.

### Commands and final results

Commands run from the repository root. Browser writes and backend database suites ran sequentially. Independent frontend/static/readiness work ran separately. Logs and canonical JSON snapshots are under `/tmp/coreerp-notification-f-fix-*`; browser artifacts are under `/tmp/coreerp-notification-f-fix-browser-artifacts`. Log redirection/tee does not alter any command environment. There were no application-database migration/reset commands or dependency installations.

| Exact validation command | Result |
| --- | --- |
| `docker compose exec -T backend php vendor/bin/pest tests/Feature/Notification/NotificationPersistenceTest.php tests/Feature/Notification/NotificationReadLifecycleTest.php --compact` | Before browser: 24 / 140 against 7 retained rows; after targeted browser: 24 / 140 against 8 retained rows |
| `docker compose exec -T backend php vendor/bin/pest tests/Unit/Notification tests/Feature/Notification tests/Integration/NotificationTransactionRequirementTest.php tests/Unit/Organization/OrganizationNotificationsTest.php tests/Feature/Application/Notifications/InvitationNotificationIntegrationTest.php tests/Integration/InvitationNotificationAtomicityTest.php tests/Integration/InvitationConcurrencyTest.php --compact` | Complete Notification/producer/atomicity/concurrency: 275 passed / 1207 assertions |
| `docker compose exec -T backend php vendor/bin/pest tests/Unit/Notification tests/Feature/Notification tests/Integration/NotificationTransactionRequirementTest.php tests/Unit/Organization/OrganizationNotificationsTest.php tests/Feature/Application/Notifications/InvitationNotificationIntegrationTest.php tests/Integration/InvitationNotificationAtomicityTest.php tests/Integration/InvitationConcurrencyTest.php --compact --order-by=random --random-order-seed=1606` | After complete browser suite, against 10 retained rows: 275 passed / 1207 assertions |
| `docker compose exec -T backend php vendor/bin/pest tests/Architecture --compact` | 46 passed / 576 assertions |
| `docker compose exec -T backend composer quality` | Both before and after targeted browser: 761 passed / 4222 assertions; no failures/skips; Pint 215 files; Larastan level 8, 144 files, no errors |
| `docker compose exec -T backend php vendor/bin/pint tests/Feature/Notification/NotificationPersistenceTest.php tests/Feature/Notification/NotificationReadLifecycleTest.php tests/Feature/Notification/NotificationIntegrityTest.php tests/Feature/Notification/NotificationConsumerSecurityTest.php tests/Integration/NotificationTransactionRequirementTest.php tests/Support/NotificationFixtures.php tests/Support/NotificationReadFixtures.php` | Seven scoped test files passed; no formatting rewrite required |
| `docker compose exec -T backend composer dump-autoload --optimize --strict-psr --strict-ambiguous` | Passed, 9147 classes |
| `docker compose exec -T backend composer validate --strict` | Valid |
| `docker compose exec -T backend composer check-platform-reqs` | All passed, PHP 8.5.11 |
| `docker compose exec -T backend composer audit` | No security advisories |
| `docker compose exec -T frontend npm run quality` | ESLint/Prettier/TypeScript passed; 91 tests / 12 files; production build passed, 131 modules |
| `docker compose exec -T frontend npm audit` | Zero vulnerabilities |
| `docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -v /tmp/coreerp-notification-f-fix-browser-artifacts:/tmp/coreerp-playwright-results -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test e2e/notifications.spec.ts` | Targeted real notification flow: 1 passed / 0 failed, 11.3s |
| `docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -v /tmp/coreerp-notification-f-fix-browser-artifacts:/tmp/coreerp-playwright-results -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test` | Complete suite: 6 passed / 0 failed, 18.1s |
| `docker compose config --quiet`; `docker compose ps` | Valid; all five existing services healthy |
| `docker compose exec -T postgres pg_isready -U coreerp -d coreerp`; `docker compose exec -T redis redis-cli ping` | Accepting connections; PONG |
| `curl -fsS http://localhost:8088/api/v1/health`; `curl -fsS http://localhost:8088/api/v1/ready`; `curl -fsS http://localhost:5174/api/v1/ready`; `curl -fsS -o /tmp/coreerp-notification-f-fix-mailpit-info.json -w '%{http_code}\n' http://localhost:8026/api/v1/info` | API health/readiness and frontend proxy status ok; Mailpit HTTP 200 |
| `docker compose exec -T backend php artisan migrate:status` | All seven existing migrations Ran, batches 1–7 |
| `docker compose exec -T backend php artisan tinker --execute="dump(config('database.default'), DB::selectOne('SELECT current_database() AS name')->name);"` | pgsql / coreerp |
| `docker compose exec -T postgres psql -U coreerp -d postgres -Atc "SELECT count(*) FROM pg_database WHERE datname IN ('coreerp_concurrency_test', 'coreerp_notification_e_validation')"` | Zero; both disposable databases absent after final tests |
| `docker compose exec -T postgres psql -U coreerp -d coreerp -Atc "SELECT count(*) FROM pg_constraint WHERE conrelid = 'organization_notifications'::regclass AND conname IN ('notifications_test_failure', 'notification_read_test_failure');"` | Zero; test constraints removed by rollback |
| `cmp /tmp/coreerp-notification-f-fix-initial-rows.json /tmp/coreerp-notification-f-fix-before-browser-rows.json`; `cmp /tmp/coreerp-notification-f-fix-after-targeted-browser-rows.json /tmp/coreerp-notification-f-fix-after-quality-rows.json`; `cmp /tmp/coreerp-notification-f-fix-after-complete-browser-rows.json /tmp/coreerp-notification-f-fix-final-rows.json` | All identical; SHA-256 pairs also match |
| `git diff --check`; `git diff -- backend/app backend/database/migrations frontend backend/phpunit.xml backend/tests/Pest.php backend/composer.json`; `git diff --cached` | Clean whitespace; production/migration/frontend/configuration/staged diffs empty |

Read-only state checks used `docker compose exec -T postgres psql -U coreerp -d coreerp -Atc "SELECT current_database(), count(*), count(*) FILTER (WHERE read_at IS NULL), count(*) FILTER (WHERE read_at IS NOT NULL) FROM organization_notifications; SELECT id, organization_id, recipient_user_id, recipient_membership_id, read_at FROM organization_notifications ORDER BY id;"`. Canonical snapshots used `docker compose exec -T postgres psql -U coreerp -d coreerp -Atc "SELECT jsonb_agg(to_jsonb(n) ORDER BY id) FROM organization_notifications n"`, redirected to initial/before-browser/after-targeted-browser/final snapshot files, then compared with `cmp` or an ID-keyed Python comparison. Additional inspection used `rg`, `cat`, `sed`, `git branch --show-current`, `git status --short`, `git log -8 --oneline`, `git diff --check`, `git diff --name-only` and `git diff --stat`.

### Scope and re-review boundary

Modified only seven backend test/support files and this validation record. Created no repository files. Production backend source (including AcceptInvitation), API, authorization, membership-era behavior, payload validation, semantic target allowlist and credential exclusion are unchanged. All historical migrations, frontend source/tests, manifests/locks, Composer/phpunit/Pest database configuration, Compose and CI are unchanged. ADR 0008 makes no test-isolation claim requiring architectural amendment, so it and README are untouched.

Exact modified files:

- `backend/tests/Feature/Notification/NotificationConsumerSecurityTest.php`
- `backend/tests/Feature/Notification/NotificationIntegrityTest.php`
- `backend/tests/Feature/Notification/NotificationPersistenceTest.php`
- `backend/tests/Feature/Notification/NotificationReadLifecycleTest.php`
- `backend/tests/Integration/NotificationTransactionRequirementTest.php`
- `backend/tests/Support/NotificationFixtures.php`
- `backend/tests/Support/NotificationReadFixtures.php`
- `docs/phases/phase-01-notification-center-validation.md`

No remaining retained-notification test-order dependency was found. The existing serial database-suite requirement remains: transactional DDL tests and browser writes must not run concurrently. This correction concerns order independence of supported sequential workflows; it does not authorize parallel database/browser execution or broaden the test architecture. No production-volume deployment, hosted CI or new independent review was performed. All requested local checks passed; R1 is fully resolved locally and Phase 1.6 is ready for final read-only re-review. Phase 1.6F-Fix stops here; no new phase begins.
