# ADR 0008: Notification Center architecture

- Date: 2026-10-03
- Status: Accepted design; 1.6B approved/committed as 1627633; 1.6C query/read lifecycle complete locally, awaiting review. Phase 1.6 remains incomplete.
- Scope: Phase 1.6 Notification Center foundation, following ADR 0005 and the supporting-capability pattern in ADR 0007.

## Context

Organization owns membership and authorization; Identity owns credentials. Audit records required business facts and retains evidence under append-only protections. A Notification Center provides private, user-facing information with mutable read state. It must have explicit tenant and recipient scope without coupling to another module's models or converting every Audit fact into a message.

The approved design revision requires membership-era isolation. A user removed from an organization must not recover notifications from that membership by joining again. Membership IDs are existing PostgreSQL bigints; removal deletes the membership. Suspension retains the same ID. No membership-history mechanism is introduced.

## Decision and dependency boundaries

Notification is a separate supporting module. Checkpoint B contains only Application and Infrastructure: public contracts, readonly data, stable vocabulary, payload/target validation, deterministic text rendering, Query Builder persistence and explicit provider wiring. C adds HTTP Presentation for recipient-private queries/read state. There is no Domain, Eloquent model, event bus, observer or generic channel abstraction.

Notification imports no Organization, Identity or Audit types. Organization Infrastructure implements Notification's access port using OrganizationAccess and persisted membership queries. Its adapter/provider may import only NotificationOrganizationAccess and NotificationMembershipContext. Other modules cannot consume Notification Infrastructure or its private validation/content/exception implementation. B adds no producer dependencies to Organization Domain/Application or to Identity/Audit.

Notification history is neither Audit evidence nor credential delivery. It is mutable user-facing state, may cascade on organization/user deletion, and does not have Audit's append-only triggers. Identity's Notifiable trait, verification/password-reset mail and Organization's invitation SMTP delivery remain unchanged. Credentials and invitation URLs never become notification payloads. No automatic Audit-to-Notification conversion exists.

## Recipient privacy and membership eras

Every row stores organization_id, recipient_user_id and recipient_membership_id. The last value is a trusted historical scope resolved by the publisher; producers cannot supply it.

NotificationOrganizationAccess::resolveActiveMembership(int $userId, string $organizationId) returns a readonly context with organization ID, user ID and membership ID. It serves publishing and C's authenticated actor scope resolution. The caller must supply trusted actor context for consumption; the port alone does not authenticate arbitrary IDs.

OrganizationNotificationAccess delegates fresh organization visibility to OrganizationAccess, then explicitly queries persisted membership by organization, user and active status. Owners have no bypass: they must have an active membership too. A missing, removed, suspended or wrong-tenant recipient is ineligible. There is no ambient auth, selected tenant, loaded relation or cached membership decision.

- Active → suspended → active keeps the same membership ID. Access is denied while suspended; reactivation restores that era's scope.
- Removal makes the era inaccessible and leaves its notification rows intact.
- Removal + later rejoin with a **new membership ID does not restore notifications from the prior membership**. New messages address only the new era.

C's list, unread count, mark-read and mark-all-read paths each resolve current active context and apply all three predicates; future consumer paths must preserve them:

```sql
organization_id = :current_organization
AND recipient_user_id = :authenticated_actor
AND recipient_membership_id = :current_active_membership
```

Notification ownership grants neither member-list authority nor target permission. An informational notification can remain visible while its semantic destination is unavailable; future destination navigation must use current authorization.

## Public producer API and initial content

```php
interface NotificationPublisher
{
    public function publish(#[SensitiveParameter] NotificationDraft $notification): void;
}
```

NotificationDraft is readonly: organizationId (ULID string), recipientUserId (positive PHP int), type (NotificationType), payload (array), target (nullable NotificationTarget, default null), payloadVersion (int, default 1). It accepts no generated ID, timestamp, title/body or recipient membership. NotificationTarget is readonly with typed target vocabulary and nullable ID. NotificationMembershipContext is readonly with string organizationId and integer userId/membershipId. NotificationText is a small readonly renderer result, keeping the two server-generated snapshots together.

The sole type is organization.invitation_accepted. Version 1 has exactly:

```json
{
  "invitation_id": "01ARZ3NDEKTSV4RRFFQ69G5FAX",
  "membership_id": "47",
  "accepted_user_id": 29
}
```

invitation_id is a structurally valid ULID; membership_id is a positive canonical decimal string within signed bigint range, consistent with existing membership subject representation; accepted_user_id is a positive PHP integer. Payload membership_id refers to the newly accepted invitee membership and is independent of the owner's recipient_membership_id. Payload identifiers are supplied facts: B does not look up invitation existence, validate a business acceptance or prove these references belong to the organization. The future authorized producer owns that business projection.

Validation rejects missing/unknown keys, wrong types, malformed IDs, unsupported versions, invalid UTF-8/NUL, excessive depth and payloads over 8 KiB. The size check conservatively uses JSON pretty-print encoding; SQL independently bounds canonical JSONB text bytes. Maximum traversal depth is three including root/scalars, key length is 80 bytes, and recursive/deeper arrays fail. Values are inspected before type-specific validation. No truncation or sanitization-and-continue is permitted.

Normalized credential-key rejection provides defense in depth for password/hash, token/reset/invitation/access/refresh, URL, CSRF/XSRF, session/cookie, authorization/auth, API/access keys, credential/secret, mail credentials and encryption/private keys. Exact schemas remain the primary control. No emails, names, request/model dumps, arbitrary copy or credential fields are accepted; stable user IDs still constitute identifiable data, not anonymity.

The renderer receives validated type/version/payload only and does no lookup or template interpretation. Version 1 snapshots are:

- Title: Invitation accepted
- Body: User #29 accepted an invitation and joined the organization.

Only the positive accepted_user_id changes in the body. No HTML or Markdown is generated/interpreted. Stored snapshots are stable if user or module data later changes. Future presentation must escape text and provide safe unsupported-version behavior.

The only target is organization.users with no target ID. An informational no-target notification uses null for both fields. Payload/type/target compatibility is central; SQL also rejects unknown targets, any users target ID, or null type with a nonnull ID. No organization target is added speculatively. Targets are semantic values, never URLs, route names, query strings, fragments or executable locations. The future frontend owns the allowlisted mapping and current authorization.

The first planned producer is AcceptInvitation in checkpoint D: after successful membership/grant/acceptance persistence, address the current organization owner through this API in the same transaction. B does not modify that command, publish from another workflow or create ordinary application messages. C is queries/read lifecycle only; actual producer integration remains D.

## Transaction and failure contract

DatabaseNotificationPublisher resolves the default application connection per call and requires driver pgsql, Laravel transactionLevel() > 0 and PDO inTransaction() === true. It starts no transaction, commits nothing, chooses no alternate connection, and queues/dispatches no work. Physical transaction verification protects against a stale Laravel counter after external rollback, following Audit's proven guard.

The publisher validates recipient identifiers, checks the transaction, resolves fresh eligible membership, verifies the returned organization/user/positive membership scope, validates exact content/target, renders snapshots and inserts explicitly. It generates the ULID and leaves created_at to database clock_timestamp(); read_at starts null. Repeated publication creates distinct rows; no idempotency/retry promise is made.

Any invalid draft, recipient denial, inconsistent context, rendering error or persistence error propagates NotificationWriteFailed. Its fixed message is Notification publication failed. Diagnostic categories are an internal allowlist; no raw inputs, SQL bindings, failing-row details or previous exception chain are retained. SensitiveParameter marks draft/payload arguments on relevant entry points. Caller-owned orchestration must let the failure abort/roll back its transaction; the writer cannot undo earlier writes if a caller deliberately catches and continues. Tests prove rollback of publication and prior caller writes, including real PostgreSQL insertion failure.

## Storage and integrity

New migration: 2026_10_03_000001_create_organization_notifications_table. Historical migrations are unchanged.

| Column | PostgreSQL representation |
| --- | --- |
| id | char(26) primary key |
| organization_id | char(26), required organization FK |
| recipient_user_id | bigint, required user FK |
| recipient_membership_id | bigint, required historical era scope |
| type | varchar(80), required |
| payload_version | smallint, required |
| payload | jsonb, required |
| title / body | varchar(160) / varchar(1000), required |
| target_type / target_id | varchar(32) / varchar(64), nullable |
| read_at | timestamptz(6), nullable |
| created_at | timestamptz(6), required, default clock_timestamp() |

Organization/user FKs use ON DELETE CASCADE and ON UPDATE RESTRICT. recipient_membership_id deliberately has **no live membership FK**: RESTRICT would prevent removal, CASCADE would delete history, and SET NULL would lose the required era. A positive historical bigint preserves the approved security rule without adding membership history. The writer validates it against persisted active context; raw privileged SQL can fabricate a positive era without referential proof. No CHECK attempts a cross-table active-membership assertion.

Named checks enforce both ULID structures, positive recipient user/membership IDs and payload version, lowercase bounded context.action type syntax, JSON object shape, canonical JSONB text <=8192 bytes, nonblank title/body, target/null combinations and read_at null or >= created_at. varchar/smallint/bigint/not-null types supply additional bounds. Exact supported types, version-1 fields and credential/PII exclusions remain Application-owned; structurally valid raw SQL can bypass them.

Indexes are limited to primary key, recipient_user_id for FK deletion checks, descending (organization_id, recipient_user_id, recipient_membership_id, created_at, id) chronology, and partial (organization_id, recipient_user_id, recipient_membership_id) WHERE read_at IS NULL. No JSON GIN, totals, speculative filter index or delivery fields exist. There is no updated_at, soft deletion, archive, metadata or email state.

## Read lifecycle, retention and deferred work

Checkpoint C implements private bounded list/unread-count and idempotent read/mark-all-read operations with fresh active context and the mandatory era predicate on every path, detailed below. Initial read state is null; marking read uses trusted database time with read_at >= created_at. B implemented no consumer queries; C adds no unread-reset, deletion or archive.

There is no expiry/purge job or retention duration in B. Rows from removed memberships remain inaccessible until a separately authorized retention policy or organization/user deletion removes them. Account/organization deletion workflows are not introduced; existing Audit references may independently restrict those deletions. Migration down() destroys notification history and is tested only inside rollback-protected schema tests. Prefer retaining populated storage during code rollback.

Phase 1.6 means Notification Center foundation. Generic email delivery, notification preferences, Horizon/queues, jobs/failed-jobs tables, retry/outbox infrastructure and Reverb/Echo/broadcasting are deferred to separately authorized milestones. Credential/invitation email remains its current synchronous behavior. No claim is made that Phase 1.6 or queues are complete.

## Threat model and verification limits

The contract protects against normal application input mistakes, cross-tenant recipient selection, producer era forgery, credential/PII fields, unsafe targets, unsupported schemas and lost atomicity under correct caller orchestration. Runtime PostgreSQL tests and membership-era tests supplement architecture checks. B proves stored/resolved era differences and retained IDs; C's consumer tests prove no old-era row is returned, counted or mutated through the API after rejoin.

This is not tamper-proof evidence or complete delivery: privileged SQL/table owners can fabricate or mutate rows, bypass Application schemas or change infrastructure. Authorization is a fresh check without new membership locks; requests already authorized can finish during concurrent suspension/removal, as in existing Organization access semantics. A captured removed-era row remains inaccessible to a later membership; the future D producer uses the existing organization transaction/locking workflow. No new concurrency linearization guarantee is claimed by B. Trace redaction cannot protect credentials another caller logs separately. Production retention, read throttling, least-privilege database roles, representative-volume query plans and deployment hardening need later review.

See [checkpoint validation](../phases/phase-01-notification-center-validation.md) for tests, exact commands and operational results.

## Phase 1.6C — Recipient-private query and read lifecycle API

C adds four independently authorized Application entry points: ListNotifications, GetUnreadNotificationCount, MarkNotificationRead and MarkAllNotificationsRead. A small internal ResolveNotificationMembership operation calls the existing access port and asserts the returned context matches the explicit actor/organization and has a positive membership ID. No actor, membership era or time comes from client-supplied scope fields. Organization's adapter and B's publisher/validation/rendering/storage schema remain unchanged. No permission, capability metadata or owner privacy bypass is introduced.

Internal NotificationReader exposes read(context, criteria) and unreadCount(context); NotificationReadStore exposes markRead(context, notificationId) and markAllRead(context). They are concrete persistence operations with trusted scope, not public generic CRUD/repositories. NotificationServiceProvider explicitly binds their Query Builder implementations. Notification imports no Organization, Identity or Audit source; the existing Organization access bridge is the only cross-module adapter.

All four endpoints require auth:sanctum and verified middleware, with string ULID organization routing outside Organization model binding:

| Method | Path under /api/v1 | Success |
| --- | --- | --- |
| GET | /organizations/{organization}/notifications | 200, bounded private page |
| GET | /organizations/{organization}/notifications/unread-count | 200, data.unread_count |
| POST | /organizations/{organization}/notifications/{notification}/read | 204, newly/already read |
| POST | /organizations/{organization}/notifications/read-all | 204, including zero unread |

The static read-all route is registered before the item route; notification IDs have structural ULID constraints. No creation/delete/update/archive/mark-unread endpoint exists. Guest gets 401, unverified gets 403, suspended/removed/nonmember/foreign gets hidden 404. Active members and owners see only their own current era. Item lookup never happens globally: absent, foreign tenant, foreign recipient and old-era IDs share the fixed Notification not found. 404 response.

ListNotificationsRequest follows the established authorization-first Audit pattern using Notification-owned types. It resolves active scope before the shared pure query validator runs. ListNotifications independently repeats fresh scope resolution and validation before persistence, protecting direct calls and changes after HTTP validation; this deliberately adds a second small access check for HTTP list requests. Every Application invocation resolves scope once. Hidden scope wins over malformed cursor/per_page/unsupported keys. Authorized invalid input receives fixed 422 field errors without supplied values. Count/read commands authorize independently too; middleware remains the trusted identity/verification adapter, not the tenant/privacy boundary.

Only cursor and per_page are list parameters. per_page defaults to 25 and accepts canonical integers 1–100. No recipient, membership, type/unread/date/search/actor/target/sort input is supported. Unsupported keys are rejected rather than ignored. Existing Laravel HTTP string normalization remains in effect; Application callers receive the same explicit pure validator without ambient request/auth/session.

NotificationCursor is Notification-owned, unsigned, unpadded canonical base64url JSON with exactly v=1 (integer), created_at (strict real UTC calendar time with six fractional digits), and id (structural ULID). Encoded length is at most 256 bytes. Strict base64/JSON/depth/field/type checks reject padding, noncanonical encodings, duplicates/unknown keys, invalid dates/year zero and unsupported versions. A cursor is a navigation position, never authority. It has no recipient or tenant authority and needs no HMAC: foreign tenant, recipient and old-era replay leave the mandatory scope intact.

DatabaseNotificationReader applies the scope first and groups cursor position as (created_at < cursor_time OR (created_at = cursor_time AND id < cursor_id)). Ordering is created_at DESC, id DESC, with a per_page+1 limit. Only per_page rows are projected; the extra row determines has_more and the last returned row determines next_cursor. There is no OFFSET, SELECT *, join, raw-payload selection, JSON filter, total count, N+1 lookup or cache. The B chronology index matches this access path. Pagination is a live view, not an export snapshot; refresh sees newer rows ahead of a previous cursor.

Readonly NotificationCriteria, NotificationView and NotificationPage keep persistence and HTTP separate. List data contains exactly id, stored type string, payload_version integer, title, body, semantic target {type,id} or null, nullable read_at and created_at. Dates are explicit UTC ISO-8601 with six fractional digits. Unknown future type/version values are returned as data without enum hydration or payload interpretation; the consumer never loads raw payload. Tenant/recipient/membership fields, relations, email, credentials and internal metadata are absent. Targets are semantic values constrained by B's SQL and never resolved into URLs or routes; future frontend mapping/escaping belongs to E.

Unread count performs one scoped COUNT with read_at IS NULL, returning a nonnegative PHP integer and using the B partial unread index. There is no total history or all-organization aggregate, cache or polling. Response is {data: {unread_count: integer}}.

Mark-one performs a conditional scoped UPDATE by all three scope fields plus id and read_at IS NULL. read_at is GREATEST(clock_timestamp(), created_at): database time clamped to creation protects the existing constraint during a clock adjustment or future-dated record. It never overwrites an already-read value. If zero rows change, a second EXISTS query uses the identical scope/id; already-read succeeds, absent fails with the same fixed 404. This is safe under PostgreSQL's conditional-update recheck for competing replay. It adds no transaction/locking workflow: the single write is atomic, while the existence fallback carries no content/ownership lookup. The database-level replay test asserts the conditional SQL and identical scoped fallback and proves the first timestamp remains stable; no new multiprocess harness is introduced.

Mark-all performs one UPDATE of current-scope unread rows using the same trusted clock expression. Already-read timestamps and other recipients/tenants/eras remain untouched; zero unread succeeds. It applies to rows visible to that statement. Later inserts can remain unread, so it does not promise a permanent zero count; callers should refetch. Neither mutation accepts a client timestamp or arbitrary update values, and both preserve stored content/payload. No read-side Audit fact, notification, event or observer is created.

NotificationNotFound maps to a safe fixed 404 and is not reported as an operational fault. NotificationQueryInvalid maps to standard 422 validation. Consumer reader/store SQL failures are replaced by NotificationStorageFailed with no retained SQL/binding exception chain and render a fixed 503 without debug internals. This extra exception is needed for safe unavailable-storage responses, distinct from normal missing private items and B's publication failures.

C requires no new or edited migration. Producer integration, frontend center/badge/polling/target navigation, email, queues/Horizon, retry/outbox, realtime/Reverb and preferences remain pending. AcceptInvitation and every existing business command are unchanged; no ordinary flow publishes yet. Review read throttling, production-scale plans and existing already-authorized revocation races at deployment. C is implemented locally for review after validation; Phase 1.6 remains incomplete and D is not begun.
