# ADR 0007: Tenant-scoped audit recording with transactional, append-only persistence

- Status: Accepted design; Phase 1.5B/C/D approved and committed; Phase 1.5E Authorized Audit Query API complete locally, awaiting review
- Date: 2026-10-01

## Context and checkpoint boundary

CoreERP's Identity and Organization contexts follow ADR 0005. Organization owns memberships, tenant RBAC and invitations under ADR 0006. Sensitive Organization mutations need attributable history; future approved business modules will need the same recording capability. Technical Laravel logs do not provide that history.

Checkpoint B established the recording contract, immutable input data, initial vocabulary, strict payload validation, PostgreSQL storage/protection, explicit provider wiring and tests. Checkpoint C instruments only CreateOrganization, RenameOrganization and SaveRole, emitting organization.created, organization.renamed, role.created and meaningful role.updated facts. Checkpoint D instruments the seven invitation/member lifecycle commands, completing the eleven approved mutation facts. Checkpoint E adds audit.view, the authorized bounded read API and show-only capability metadata. There is no Audit Policy or frontend. Phase 1.5 is not complete; F requires separate authorization.

## Ownership and layers

Audit is a separate supporting module. It owns safe recording, immutable storage and authorized history delivery. Organization continues to own the business facts and actor authorization. Putting Audit under Organization would give tenant administration inappropriate ownership of future Sales/Finance history; making it a platform logger would obscure its payload/disclosure semantics.

Audit has Application, Infrastructure and the E HTTP Presentation layer. It has no independent aggregate lifecycle or rich business invariant needing a Domain layer. Its schema validation and immutable inputs are Application recording concerns. Presentation adapts the authorized history query through a Form Request, thin controller and explicit Resource. No empty layers, generic repository or Eloquent audit model exist.

An audit event is a persisted fact about a successful business/security mutation. It is not automatically a Domain Event and does not dispatch consumers. Technical logs describe diagnostics; global authentication/security logs need separate scope, volume, privacy and retention decisions. No event sourcing, event bus, queue, outbox or authentication-event instrumentation is introduced.

## Public producer API and dependency direction

The public producer surface is exactly Audit Application `Contracts`, `Data` and `Vocabulary`:

```php
interface AuditRecorder
{
    public function record(AuditEntry $entry): void;
}
```

`AuditEntry` is readonly input containing organization ID, `AuditActor`, `AuditAction`, `AuditSubject`, before/after snapshots and payload version. Producers never supply event ID or recording time. Snapshots enter as arrays/null but are accepted only after exact action-specific schemas validate them. Arrays containing objects, floats or unsupported values are rejected; there is no generic model/request serialization.

`AuditActor` and `AuditSubject` are readonly scalar/value structures. Actor vocabulary is a backed `AuditActorType` enum so null cannot silently imply a system actor. Subject accepts an integer/string and preserves its string representation; the validator then requires the identifier appropriate to its subject type.

Implemented producer direction:

```text
Organization/future owning module Application
    -> Audit Application Contracts/Data/Vocabulary
Audit Infrastructure
    -> Audit Application
    -> Laravel's current default PostgreSQL connection
```

Audit imports no Organization or Identity classes. Table foreign keys are intentional database integrity relationships, not permission to import foreign Eloquent models. Validation and persistence implementations are private. Other modules may not consume Audit Infrastructure, Presentation, Validation or exception implementation details. E adds the separate AuditHistoryAccess contract, implemented only by the Organization Infrastructure authorization adapter and wired by its provider; producer imports are unchanged.

Producers build deliberate, named module-owned projections from scoped persisted records. The recorder validates shape and vocabulary, not actor authority or another module's subject existence/ownership. Those checks remain in the initiating use case. No producers were added in B.

Organization uses one model-free Application factory, `Auditing/OrganizationAuditEntries`. C introduced four named methods; D adds invitationCreated, invitationRevoked (including optional replacement ID), invitationAccepted, membershipRolesChanged, membershipSuspended, membershipActivated and membershipRemoved. Its explicit scalar business values and permission/role-ID lists never contain models, requests, emails or invitation credentials. It normalizes lists to sorted unique stable strings and returns null for unchanged role/assignment sets. Only the factory imports AuditActor, AuditEntry, AuditSubject, AuditAction and AuditSubjectType; all ten write commands import AuditRecorder. Fifteen imports / six distinct public types remain confined to Contracts/Data/Vocabulary. No Organization Domain or Presentation code imports Audit. E adds only the named Infrastructure authorization adapter/provider imports of AuditHistoryAccess; write producer imports stay unchanged.

## Transaction and failure semantics

Recording is explicit and synchronous. The recorder resolves the current default application PostgreSQL connection on every call. It requires both Laravel's transaction level to be positive and PDO to report a physical transaction. Unsupported drivers, absent transactions and stale transaction counters fail safely.

It generates a ULID and inserts explicit columns through Query Builder. It never creates a second connection, starts/commits a transaction, calls afterCommit, queues work or logs payloads. PostgreSQL supplies `created_at` with `clock_timestamp()`.

Instrumented business mutation and audit insert must commit together or both roll back. A validation/persistence failure propagates as a safe `AuditWriteFailed`; producers must not catch and ignore it. The exception has a fixed message and allowlisted diagnostic category, with no previous database exception. QueryException bindings and PostgreSQL failing-row details must not reach framework exception logging through a retained exception chain. Sensitive input parameters are marked with PHP's SensitiveParameter attribute.

CreateOrganization records after organization and owner membership insertion in its existing transaction, using the explicit owner ID and persisted membership ID. RenameOrganization now authorizes, reloads by ID with FOR UPDATE, compares the authoritative name and records only a change inside one transaction. After success it synchronizes persisted attributes back into the supplied instance; stale/dirty caller attributes are never saved. A failed transaction leaves that instance untouched. A stale A / persisted B / requested C transition records B to C; requesting persisted B is a no-op.

SaveRole retains its transaction, scoped role row lock, typed PermissionKey inputs, name trimming, unique-name conflict and HTTP 422 translation. Creation records after grants are synchronized. Update reads the locked persisted name/permissions, then records only changed fields after persistence. Equivalent normalized names/permission sets emit no fact. Audit failure restores a created role/grants or the prior name/grants; duplicate conflicts leave no successful fact. These facts describe explicit command workflows, not model observers or internal pivot writes.

D retains the existing organization-first, then invitation/membership row locks and reauthorization. CreateInvitation records persisted pending state, freshly reloaded database expiration in canonical UTC and actual persisted invitation grants. Reinvite revokes the old credential and creates its replacement, then records old invitation.revoked (reason=replaced plus final replacement ID) followed by new invitation.created in the same transaction. Neither event can survive failure of the other. RevokeInvitation records only pending-to-revoked; accepted/already-revoked no-ops remain successful without new history.

AcceptInvitation retains verified matching identity, credential/state/expiration checks, unique membership and replay protection. It records one invitation.accepted after membership/grants persist and invitation state becomes accepted, using the accepting actor and actual membership/user/assigned-role IDs. SyncMembershipRoles compares fresh persisted role sets before/after synchronization. Suspend/Activate skip repeated status no-ops while preserving owner protection and retained grants. RemoveMembership captures locked user/status/roles before deletion, then records after successful deletion; history survives because the subject has no FK. Internal AssignMembershipRole, ResolveOrganizationRoles and LockManagedMembership do not emit facts.

Invitation mail callback registration remains before recording inside the transaction. Tests prove Laravel discards it on rollback, including a failed second replacement audit insert and a later successful transaction. Email delivery stays synchronous after physical commit; invitation.created asserts persisted issuance, not SMTP success. A real delivery adapter with a failing effective SMTP transport proves existing safe HTTP 503 leaves invitation and audit history committed. No delivery event, queue or redesign is introduced.

The physical recording clock is not commit order. ULIDs and timestamps do not globally serialize concurrent business commands. There is no global audit write lock.

## Tenant and actor identity

Every row has `organization_id NOT NULL`. Global authentication or hypothetical platform events cannot use null tenants in this table.

- User actor: positive integer `actor_user_id`, type `user`.
- System actor: null user ID, type `system`.

Application and PostgreSQL enforce the combination independently. Current Organization producers use authenticated user actors; schema support for system attribution grants no new business authorization bypass.

No actor name/email snapshot is stored. Historical actor attribution is the stable user ID; a later UI can initially display User #ID. No current Identity projection or polymorphic actor machinery is required. Identifiers remain potentially sensitive data even without duplicated email addresses.

## Stable action and subject vocabulary

Subjects are `organization`, `role`, `invitation`, `membership`, never PHP class names. Organization/role/invitation IDs are valid ULIDs, preserving case: the installed Laravel 13 HasUlids implementation creates lowercase IDs, while Str::ulid produces uppercase strings. Membership IDs are canonical positive decimal bigint strings up to 9223372036854775807. Organization subjects must equal the explicitly supplied organization ID.

Initial server-owned action enum:

```text
organization.created       organization.renamed
role.created               role.updated
invitation.created         invitation.revoked       invitation.accepted
membership.roles_changed   membership.suspended
membership.activated       membership.removed
```

Action -> subject mapping is explicit. No arbitrary action string can enter AuditEntry. SQL validates bounded action identifier syntax, subject vocabulary and identifier structure; exact action/version schemas remain Application-owned. Raw privileged SQL can therefore insert syntactically valid unsupported actions or otherwise false facts; database append-only protection does not establish their provenance.

## Version-one payload schemas

Each snapshot is a JSON object or SQL null. At least one exists. Create uses null before, removal uses null after. Update snapshots contain matching changed fields, except deliberate result facts on invitation acceptance/replacement. No-op transitions are rejected by the recorder; producers skip recording them.

| Action | Before | After |
| --- | --- | --- |
| organization.created | null | name, owner_user_id, owner_membership_id |
| organization.renamed | name | changed name |
| role.created | null | name, permissions |
| role.updated | name and/or permissions | same keys, each changed |
| invitation.created | null | state=pending, expires_at, role_ids |
| invitation.revoked | state=pending | state=revoked; optional paired reason=replaced and replacement_invitation_id |
| invitation.accepted | state=pending | state=accepted, membership_id, user_id, role_ids |
| membership.roles_changed | user_id, role_ids | same user_id, changed role_ids |
| membership.suspended | user_id, status=active | same user_id, status=suspended |
| membership.activated | user_id, status=suspended | same user_id, status=active |
| membership.removed | user_id, status=active/suspended, role_ids | null |

Creation's owner and acceptance's resulting user must equal the user actor. Replacement cannot point to the revoked invitation itself. Subject/role IDs are structurally checked but their tenant ownership remains producer responsibility.

Names are nonblank strings, bounded to the existing organization 255/role 80 character limits. IDs in facts are positive PHP integers. Permission lists use five reviewed keys (`audit.view`, `members.invite`, `members.view`, `organizations.update`, `roles.view`), deliberately without importing Organization's Domain enum. E explicitly extends the version-1 permission vocabulary so audited role administration can grant/revoke audit.view; historical payloads are unchanged. Future permissions still require a reviewed schema-vocabulary extension with tests. Lists are sequential, sorted by exact string value and duplicate-free; validation rejects instead of sorting/truncating input. Expiration is a real calendar timestamp in canonical UTC `YYYY-MM-DDTHH:MM:SS.ffffffZ` form.

Only payload version 1 is accepted now. Evolving payloads require an explicit version/schema change and tests; existing stored records must remain interpretable.

Reinvite records the old invitation's revocation and the new invitation's creation atomically, not a redundant third event. Acceptance includes membership/bootstrap grants; internal pivot operations should not create duplicate facts. Derived expiration, low-value reads, failed commands, fixture/migration writes and global auth events have no records here. Existing history is not backfilled with invented observations.

## Secret rejection and payload bounds

Exact per-action required/optional field allowlists are the primary guard. Unknown keys, missing keys, wrong types, unsupported action/subject/version, illegal transitions and malformed identifiers fail recording; no sanitize-and-continue behavior exists.

Recursive defense in depth normalizes key case and punctuation and rejects password/password-hash/reset credentials, tokens/invitation-token hashes, CSRF/XSRF, sessions, cookies, authorization/credentials/secrets, API/access keys and private/encryption keys. Invitation/actor email, raw HTTP headers/requests, mail credentials/bodies, credential-bearing URLs and model dumps are never approved fields. Generic objects, non-UTF8 strings and NUL bytes are rejected.

Explicit bounds:

- Combined before+after: at most 65,536 bytes, conservatively measured using JSON_PRETTY_PRINT with normal Unicode escaping. This can reject before the PostgreSQL canonical JSONB text limit; it never silently truncates.
- PostgreSQL independently limits the combined octet_length of both JSONB text representations to 65,536 bytes.
- Depth 3 counting snapshot root as 1, field/list as 2 and list item as 3. Current schemas permit only flat scalar fields and flat string lists.
- At most 1,000 items per collection; total size also applies across both snapshots.
- Generic scalar string at most 1,024 Unicode characters; names have the tighter limits above; field names at most 80 bytes.

The validator also rejects recursive arrays through its depth guard. These controls do not detect every secret deliberately pasted into an approved free-text name. Safe projection, code review and tests remain necessary. There is no claim of universal secret-value detection.

## PostgreSQL storage and append-only behavior

B storage migration: `2026_10_01_000002_create_audit_events_table.php`. Historical migrations remain unchanged. E separately adds `2026_10_01_000003_add_audit_view_permission.php`; storage schema is unchanged.

| Column | Type / semantics |
| --- | --- |
| id | char(26) primary-key ULID, recorder-generated |
| organization_id | char(26), required FK |
| actor_type | varchar(16), required |
| actor_user_id | bigint nullable FK |
| action | varchar(80), required |
| subject_type | varchar(32), required |
| subject_id | varchar(64), required |
| before / after | nullable JSONB objects |
| payload_version | positive smallint; Application currently accepts only 1 |
| created_at | required timestamptz(6), clock_timestamp() default |

No updated_at, deleted_at or arbitrary metadata. Both actor and organization foreign keys use ON DELETE RESTRICT / ON UPDATE RESTRICT. No subject FK: history intentionally survives subject deletion. Account/organization physical deletion now requires a later architectural decision once referenced by audit rows. ON DELETE SET NULL would update immutable history and is deliberately excluded.

Indexes: primary key; actor_user_id for FK checks; (organization_id, created_at DESC, id DESC); the same chronology prefixed with action; the same chronology prefixed with subject_type/subject_id. E uses these for bounded cursor/action/subject reads. No GIN, metadata or speculative actor-filter index exists.

One small PL/pgSQL function raises SQLSTATE 55000 with a fixed append-only message. Two unconditional BEFORE FOR EACH STATEMENT triggers reject UPDATE/DELETE and TRUNCATE, including zero-row attempts. There is no application/session/test bypass flag, soft-delete path or Eloquent mutation model. Rolling back an uncommitted insert is correct transactional behavior and requires no deletion bypass.

The table, indexes, constraints and triggers are installed in the same transactional migration. down mechanically drops the triggers, function and table. **Schema rollback destroys history** and is not the preferred production application rollback: retain audit storage when reverting code. No export/archive subsystem is added.

## Threat model, retention and operations

Triggers protect normal SQL mutations. PostgreSQL superusers/schema owners or administrators with sufficient DDL privileges can disable/drop them or destroy storage. A compromised application can still issue direct business writes without instrumentation or insert fabricated audit facts. This is neither cryptographic tamper-proofing nor proof of completeness/provenance.

A production runtime role should have only necessary audit SELECT/INSERT privileges, without table ownership, UPDATE/DELETE/TRUNCATE or migration privileges. Current Compose credentials have development administrative privileges; privilege separation is documented deployment work, not implemented by B. Payload-bearing query listeners/database statement logs require the same privacy discipline as other business data; safe exception wrapping cannot control privileged external log configuration.

Retain history indefinitely for current portfolio scope. No retention/deletion API/job exists. Future privacy, archival, tenant offboarding and account erasure decisions must reconcile retained identifiers/business names, existing owner/inviter constraints and audit immutability. No email/name/IP/user-agent snapshots, broad correlation subsystem, hash chaining, signatures, blockchain or external WORM storage are included.

## Verification and later checkpoints

Unit tests exercise all initial schemas and failures without Laravel. PostgreSQL Feature tests use the existing outer rollback transactions/savepoints to prove insert, rollback, FK/JSON/size constraints, timestamp/default/index structure and statement-level rejection. A no-fixture Integration test proves absent/stale physical transaction rejection. Migration down/up is tested inside the outer rollback; it is not audit-row deletion cleanup.

No test-isolation helper, trigger disabling, audit DELETE cleanup or committed audit fixture was needed in B. In C, normal command-based setup remains audited. Storage tests and the historical RBAC cascade test use deliberately unaudited direct persistence fixtures via a small test-only OrganizationFixtures helper. The invitation concurrency test uses that raw organization/owner-membership setup too: its uninstrumented invitation acceptance still permits identified fixture cleanup, without expanding database infrastructure in C. A separate C regression proves command-created organizations cannot be hard-deleted. No foreign keys or triggers are disabled, no audit rows are deleted and no recorder is globally replaced.

Focused C Application tests assert exact persisted fact structures, trusted actor/subject identity, fresh locked before values, changed-field-only payloads, no-ops, duplicate failures and supplied-model return behavior. For each of the four paths, a narrow test-only recorder first proves writes precede recording and then throws; a second mode delegates to the real recorder, verifies a successful insert and deliberately fails before transaction completion. Both modes prove business rows/grants and audit inserts roll back together. Existing authorization and cross-tenant suites remain active. Backend database suites stay sequential and separate from browser writes.

D replaces committed business-row cleanup with test-only DisposableConcurrencyDatabase. phpunit.xml explicitly sets COREERP_CONCURRENCY_TEST_DATABASE=coreerp_concurrency_test. The helper requires the testing environment, exactly that fixed name, a distinct nonempty configured and actual application database, idle default pgsql and no URL override. A separate postgres administrative connection creates only a previously absent target; existing databases are never reused or dropped. Only a database successfully created by that invocation is dropped in finally after worker exit. Original connection config is restored. Independent workers also require testing, fixed target and a distinct explicit application database. No trigger/FK/recorder bypass or row-deletion cleanup exists.

The genuine concurrency test now uses audited organization creation, observes both independent acceptance sessions waiting on the tenant lock and proves exactly one membership, accepted invitation and invitation.accepted row. The same disposable scope supports physical mail/commit regression tests. Unit tests reject unsafe environments/names/SQL identifiers/default-target collisions; an Integration failure test proves database drop and connection restoration. Run these suites sequentially: the fixed dedicated name intentionally rejects overlapping attempts. A killed process may leave the disposable database behind; automatic reuse/deletion is refused and requires manual verification. See the D validation record for commands and privileges.

D Application tests assert every new fact, sorted persisted role sets, fresh-state/no-op behavior, trusted/delegated attribution, rejection without successful facts and rollback for issuance, both replacement inserts, revocation, acceptance, sync, suspension, activation and removal. A real acceptance audit insert followed by deliberate failure proves membership/grants/state/history atomicity. Exact invitation payload comparisons plus boolean credential/email/URL exclusion checks avoid printing sensitive values on assertion failure. Invitation email/accepting-email command parameters now carry SensitiveParameter alongside the existing acceptance token protection; audit-failure trace tests explicitly enable PHP argument traces and prove email/token/hash exclusion. Existing F1 effective-transport security tests remain unchanged.

Architecture checks enforce framework-free Audit Application, no Audit -> Organization/Identity imports, no Organization Domain -> Audit, an explicit Organization-to-Audit public namespace allowlist, no Organization delivery/infrastructure audit orchestration, private implementation isolation, no controller writes/transactions and caller-owned recorder transaction control. Runtime tests supplement static rules.

E implements the approved audit.view, authorization port, tenant history query, created_at/id cursor, action/subject filters and safe Resource below. The view-local Vue Audit Trail and browser flow remain pending F. C/D added no migrations, permissions, API or frontend changes. No future business modules are created now.


## Phase 1.5E — Authorized history read API

Organization owns authorization for `audit.view`. A new versioned migration expands permissions_known_key and inserts only this permission; down removes its role grants before its permission row and restores the previous four-key constraint. Existing keys/grants survive. Historical migrations and audit storage protections are unchanged; rollback leaves recorded role history intact.

OrganizationAccess.viewAuditHistory reads fresh persisted active membership, ownership and tenant role grants. Active owner needs no role; active member with audit.view is allowed; ordinary active member is forbidden (403); suspended/nonmember/missing tenant is hidden (404). HTTP middleware preserves guest 401 and unverified 403. There is no permission cache. Revocation, suspension and reactivation take effect at the next check. As with existing APIs, an already-authorized request can finish during a concurrent revocation.

Audit Application owns `AuditHistoryAccess::assertCanView(actorUserId, organizationId)`. Organization Infrastructure's OrganizationAuditHistoryAccess delegates to OrganizationAccess and lets its safe AccessDenied propagate. Central HTTP composition already maps that exception to hidden 404 / forbidden 403. Audit neither imports nor catches Organization authorization types. OrganizationServiceProvider binds the port; AuditServiceProvider binds only Audit-owned recording/read persistence contracts. The source edge is Organization Infrastructure → Audit Application contract, never Audit → Organization or Identity.

ListAuditEvents accepts explicit trusted actor ID, tenant ID and raw query input. It authorizes before bounded validation and invokes the internal AuditEventReader with typed AuditEventCriteria. DatabaseAuditEventReader uses Query Builder and returns readonly AuditEventView/AuditEventPage projections. There is no Eloquent AuditEvent, generic repository, Domain layer, Policy, transaction or read-generated audit fact.

`GET /api/v1/organizations/{organization}/audit-events` uses auth:sanctum and verified middleware. Organization is a route-constrained ULID string, avoiding foreign model binding. ListAuditEventsRequest authorizes through the port before its validation callback, sharing the pure Application validator. The Application query independently repeats authorization/validation to protect direct callers and see fresh state after HTTP validation. This deliberately costs a second small authorization check. Authorized invalid inputs receive fixed 422 field errors with no supplied values or SQL details; hidden/forbidden callers receive denial first.

Only `per_page` (default 25, integer 1–100), `cursor`, exact AuditAction `action`, and paired `subject_type` / `subject_id` are accepted. Unsupported query keys are rejected. Subjects use the approved four types, ULIDs or canonical positive bigint strings (membership; at most 9223372036854775807). Actor/email/free-text/date/JSON/sort filters remain deferred. Laravel's standard string normalization still applies at the HTTP boundary.

The unsigned opaque cursor is unpadded base64url JSON with exactly `v` (integer 1), `created_at` (real UTC calendar timestamp with six fractional digits) and `id` (structural ULID). Parsing bounds the encoded size at 256 bytes, uses strict decoding/canonical base64url, bounded JSON depth, exact fields and scalar types. Year zero is rejected because PostgreSQL cannot represent it. Direction is fixed descending and is not encoded. The cursor is a navigation position, never an authorization credential: replay/tampering can change navigation but cannot remove the tenant SQL predicate. No HMAC is needed.

Every read starts with `organization_id = authorized organization`, adds a grouped `(created_at < cursor_time OR (created_at = cursor_time AND id < cursor_id))` position, then optional exact filters. Ordering is created_at DESC, id DESC. Fetch is limited to per_page+1; only per_page projections return. The extra row determines has_more and the last returned row determines next_cursor. Existing tenant chronology/action/subject indexes serve these paths. There is no total count, OFFSET, arbitrary sorting, global fetch/filter, user join or N+1 actor lookup. Pagination is a live view, not an export snapshot; newly inserted rows ahead of a cursor appear when refreshing the first page.

Response: `data` holds events with id, action, actor {type,id}, subject {type,id as string}, changes {before,after}, payload_version and canonical UTC created_at. System actor id is null. Metadata is exactly `{next_cursor: string|null, has_more: bool, per_page: int}`. Tenant ID, email/name joins, tokens/hashes, credentials, relations and internal metadata are absent. Read returns stored snapshots/version without rewriting or interpreting future versions; full payload safety remains the server-owned recording-schema responsibility. Arbitrary privileged SQL insertion is not an approved producer.

Organization SHOW alone adds `meta.can_view_audit` from the same OrganizationAccess semantics after view authorization. Its data object and list response remain unchanged. Capability supports later navigation only; direct audit requests authorize independently.

No suitable general authenticated read throttle exists; the current throttles cover invitation mutation/acceptance. E therefore defers a rate-limit policy rather than adding broader infrastructure. Mandatory query/page/cursor bounds remain active. Revisit rate limiting and representative data-volume query plans during production deployment.

Tests cover the HTTP/direct authorization matrix, persisted-state/ownership checks, malformed-input non-disclosure, immediate permission revocation, retained-role suspension/reactivation, exact resources and actual invitation redaction, system/future-version representation, empty/filter results, cross-tenant cursor and subject replay, explicit SQL projection/bounds, and deterministic three-page timestamp ties with no gaps/duplicates. Fixtures INSERT explicit times and never UPDATE immutable events. Architecture checks retain framework-independent Audit Application and prohibit foreign imports, presentation persistence, read-side mutation and unauthorized Organization Infrastructure edges. The two historical permission-migration tests unwind/reapply E before their earlier schema checkpoints.

**E is complete locally for review. F — Audit Trail UI & Browser Flow remains pending. No frontend, Phase 1.4 deferred UX fix or future checkpoint is included. Phase 1.5 is not complete.**
