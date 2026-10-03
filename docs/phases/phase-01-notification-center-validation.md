# Phase 1.6 — Notification Center validation

Current status: B approved/committed as 1627633; C approved/committed as 45ceea9; **D complete locally, awaiting review**. Phase 1.6 remains incomplete; frontend E is pending explicit authorization. B/C sections preserve their original checkpoint evidence; D evidence follows them.

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
