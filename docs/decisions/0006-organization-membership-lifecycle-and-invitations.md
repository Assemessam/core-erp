# ADR 0006: Organization membership lifecycle and invitations

- Status: Accepted; Phase 1.4 implementation awaiting review
- Date: 2026-10-01

## Membership and ownership

Organization continues to own memberships and tenant RBAC under ADR 0005. `MembershipStatus` defines `active` and `suspended`. A new migration defaults all existing and new memberships to active. Suspension changes status only: membership identity and role assignments remain intact. Reactivation restores the existing membership and therefore its retained grants. Removal deletes the membership and cascades its membership-role links; it never deletes the User or organization-wide Role definitions.

`MembershipRules::requireNotOwner` is a pure Domain invariant: the explicit owner membership cannot be suspended or removed, regardless of the actor's authority. Ownership is still `organizations.owner_user_id`, separate from roles. Ownership transfer and organization deletion have no API or use case.

The original deferred PostgreSQL owner-membership FK remains. An additional deferred composite FK references `(organization_id, user_id, status)` with `organizations.owner_membership_status` constrained to the constant `active`. Together with a unique referenced key and the membership status allowlist, PostgreSQL rejects deleting or suspending an owner even through raw SQL. The constant is infrastructure bookkeeping, not client-editable ownership state. Organization-plus-owner creation still commits atomically.

`OrganizationAccess` requires persisted active membership for every capability, including ownership. A suspended member receives HIDDEN (404), retains no effective RBAC authority, and is excluded by `ListOrganizations`. Active members without a capability receive FORBIDDEN (403); permitted actors receive ALLOWED. No permission cache or ambient tenant resolver exists. Requests already authorized before a concurrent suspension may finish; subsequent checks see revocation. This milestone does not retrofit locking into earlier rename/role-definition commands.

## Invitation lifecycle and credentials

`OrganizationInvitation` is a first-class Infrastructure Eloquent model with an Organization ULID, organization FK, normalized email, inviter user FK, expiration, state, and SHA-256 token hash. There is no duplicated pure aggregate or repository. `InvitationState` contains pending, accepted, revoked, and expired vocabulary; only pending/accepted/revoked are persisted. Expired is derived when a pending invitation's expiration is at or before the current time. Pending listing includes expired pending rows so owners can reinvite or revoke them.

Expiration is seven days, centralized in `config/organization.php`. Email normalization uses trimmed, Unicode lowercase text, matching Identity registration's normalization. PostgreSQL additionally checks lowercase/trimmed nonempty storage. Database collation is the final normalization boundary; no provider-specific dot/plus-address rewriting occurs.

Each issuance generates 32 cryptographically random bytes, encoded as 64 hexadecimal characters. Only `hash('sha256', token)` enters persistence. A high-entropy random credential does not need a password KDF. Acceptance compares hashes using `hash_equals`. The hash is model-hidden and absent from all API Resources; plaintext is never returned from issuance/list APIs. Invalid and nonexistent invitations share the same safe rejection. Valid token possession alone never authorizes membership.

A partial unique index on `(organization_id, email) WHERE state = 'pending'` prevents multiple pending credentials, including expired pending rows. It contains no time-dependent index predicate. Reinvite locks the organization, revokes the previous pending invitation, and creates a new ID/hash/expiration and selected-role set in one transaction. Old credentials are immediately unusable after commit. Failure before commit preserves the previous invitation. Multiple concurrent issuances serialize on the same organization row.

Revocation uses the same lock order. Repeated revocation is a no-op; accepted invitations remain accepted and their memberships remain untouched. Reinviting either an active or suspended existing member is rejected. Suspended memberships must be reactivated explicitly.

## Verified identity binding and acceptance

`InvitationRules::requireAcceptable` independently enforces verified email, normalized email equality, pending state and strict expiration. Pure Unit tests supply deterministic time and no Laravel/database. The authenticated Presentation adapter reads user ID, email and verification from the trusted session User; those values never come from request payload. Identity owns registration, login, verification and recovery unchanged. Organization never creates a User or duplicates those workflows.

`AcceptInvitation` takes explicit trusted identity values, invitation ID and token. Inside a PostgreSQL transaction it:

1. Resolves the candidate invitation to determine its tenant.
2. Locks the organization, then reloads/locks the invitation within that organization.
3. Checks the credential, verified matching identity, state and expiration.
4. Rejects any existing membership, including suspended membership.
5. Creates one active membership and assigns all selected roles through existing `AssignMembershipRole`.
6. Marks the invitation accepted and commits all steps together.

All new invitation/member mutation paths lock organization first, then invitation/member. This intentionally coarse organization lock is sufficient for the current small administration workload and also serializes reinvite, revocation and acceptance. PostgreSQL's default READ COMMITTED isolation sees the winning state after a waiter acquires the row lock. The existing unique `(organization_id, user_id)` membership constraint remains authoritative. A second acceptance rejects as already accepted and cannot recreate membership even after later removal. No Redis/distributed lock or frontend-only protection is involved.

The integration test uses two independent PHP processes and database connections, observes both blocked on PostgreSQL locks, then releases them: one succeeds and the other rejects as accepted. Feature tests exercise replay and rollback after membership/grant insertion and final state mutation. The integration test creates committed test fixtures and removes only its own fixtures in `finally`; other backend tests retain their existing outer-transaction strategy. Never run backend suites concurrently with browser database writes.

## Roles and delegation

The new permission keys are only `members.view` and `members.invite`, added to the enum and through a new permission allowlist migration. No historical migration was changed.

| Capability | Authority |
| --- | --- |
| Member list | Owner or `members.view` |
| Invitation list/create/revoke/reinvite without roles | Owner or `members.invite` |
| Select invitation roles | Owner only |
| Revoke/reinvite an existing invitation containing roles | Owner only |
| Synchronize member roles, suspend, reactivate, remove | Owner only |

The extra restriction on existing role-bearing invitations prevents a delegated inviter from replacing an owner's role grant with their own invitation. Delegated inviters can see invitation role representations but cannot use them to grant authority. Names such as Owner or Administrator have no special meaning.

Role inputs are stable ULIDs, validated structurally by Form Requests and resolved together within the explicit organization before writes. `SyncMembershipRoles` is a transaction with authorized scoped membership locking, complete tenant-role validation before detach, and assignment through `AssignMembershipRole`. That operation remains the single membership grant implementation and invokes the existing pure cross-organization invariant. Invitation grants have their own two composite tenant FKs, mirroring the membership-role pivot. The database rejects forged tenant IDs, foreign roles and moving referenced resources across tenants. Deleting an invitation or role cascades its invitation-role links; role definitions are not copied into invitations. Selected roles and their current permissions apply at acceptance time.

## Boundaries, HTTP and email

Domain contains enums, owner protection and invitation identity/lifecycle rules only; it has no framework or persistence dependency. Application owns actor authorization, scoped resolution, locking and transactions. The documented lightweight path uses same-module Eloquent, DB and the concrete Organization mail adapter; no artificial repository, bus or generic notification service is needed. Mail is an actual infrastructure integration, invoked after commit. Presentation contains thin controllers, authorization-first Form Requests, explicit Resources and failure mapping. Policies adapt OrganizationAccess.

Seven narrow Organization adapters may reference Identity User: the two existing persistence relationships, Policy, and four authenticated actor controllers. Organization Domain/Application have no Identity dependency; Identity has no Organization dependency. Existing architecture checks automatically discover the new classes, and only the two new HTTP actor adapters are added to the precise User allowlist. No broad boundary exception is introduced.

All endpoints require authenticated, verified sessions. Organization-relative routes use scoped binding and commands independently scope nested IDs. API prefix is `/api/v1`:

| Method | Path | Success |
| --- | --- | --- |
| GET | `/organizations/{organization}/users-access` | 200, three presentation capability flags |
| GET | `/organizations/{organization}/members` | 200, deliberate member/user/role fields |
| PUT | `/organizations/{organization}/members/{membership}/roles` | 204 |
| POST | `/organizations/{organization}/members/{membership}/suspend` | 204 |
| POST | `/organizations/{organization}/members/{membership}/activate` | 204 |
| DELETE | `/organizations/{organization}/members/{membership}` | 204 |
| GET | `/organizations/{organization}/invitations` | 200, pending and derived-expired invitations |
| POST | `/organizations/{organization}/invitations` | 201, credential-free Resource |
| DELETE | `/organizations/{organization}/invitations/{invitation}` | 204 |
| POST | `/invitations/{invitation}/accept` | 200, existing Organization Resource |

Invitation issuance and acceptance are throttled to 30 requests/minute through Laravel. Errors use existing 401/403/404 semantics; Domain invitation failures render safe 422 `message` and `reason` fields. Owner protection and role-scope failures use 422 validation responses. Expected invitation failures are not reported to logs; token input is excluded from flashed input. No generic membership PATCH is exposed.

An Infrastructure Mailable is sent synchronously after commit through SMTP (Mailpit locally). InvitationDelivery resolves the named Laravel mailer with `Mail::driver()` and checks its public `getSymfonyTransport()` accessor before constructing or sending the credential-bearing email. Only Symfony SMTP transports (including Laravel's ESMTP transport) are supported for invitations. The effective transport determines safety: a raw SMTP configuration overridden by `MAIL_URL=log://localhost` is rejected. Log transports expose rendered credentials; array transports retain debug messages; failover/round-robin composites can select unsafe children and are not supported by the existing invitation SMTP-only policy. Other production-capable transports remain available in general mail configuration but were never enabled for invitations; this correction does not introduce composite traversal or a generic mail security framework. There is no queue, outbox or Phase 1.6 notification center. Transport/view exceptions are replaced with a safe failure without a chained exception, so SMTP trace arguments cannot leak the email body or invitation URL. HTTP 503 explains that the invitation was saved and should be reinvited. Delivery failure cannot roll back an already committed invitation; the caller can refresh and reinvite to rotate the credential and retry. Reliable delivery is a later explicit requirement, not a claim of exactly-once SMTP delivery.

## SPA token and navigation policy

The Users route is `/app/organizations/:organizationId/users`, linked from the workspace. It holds API results in view-local state, discards stale tenant responses, and presents member status/roles/owner, role assignment and lifecycle commands, plus invitation creation/reinvite/revoke. Backend capabilities govern controls; they are never an authorization boundary. Owner suspend/remove controls are absent. Removal asks for confirmation in the UI.

The acceptance route is `/invitations/:invitationId/accept#token=...`. URL fragments are not sent in HTTP requests or referrer headers. The SPA captures the token in a module-local memory variable and removes the fragment with router replacement. No localStorage, sessionStorage or persisted Pinia is used. A pending in-memory return path carries users through existing login/register/verification pages. Verification can open in another tab; the original tab's “I've verified” returns to acceptance. Success and terminal rejection clear the credential. A wrong-email response lets the user switch accounts.

Reloading/closing the tab after fragment cleanup or following verification in the same tab loses the in-memory credential. Reopening the original email link resumes the flow; the UI explicitly explains this. No server-side session token persistence is introduced to hide that tradeoff. Guests see login/register choices; unverified users see verification instructions; verified users explicitly accept. Expired/revoked/accepted/invalid states are terminal. There is no automatic acceptance GET and no redirect loop.

## Limits and follow-up

Lists remain unpaginated, consistent with current administration surfaces. No audit trail, ownership transfer, organization deletion, global administrator, future ERP module, notification center or distributed workflow is implemented. True cross-origin deployment still needs the earlier CORS method review (the supported same-origin Vite proxy handles these routes). Mailpit is local delivery, not a production provider. Review the coarse organization lock if administration throughput grows.

Phase 1.5 may separately introduce tenant-scoped audit records for invitation and membership actions, with actor/outcome information and strict exclusion of tokens, token hashes, passwords and mail bodies. It is not implemented or implicitly authorized here.
