# ADR 0004: Organization-scoped RBAC

- Status: Accepted for Phase 1.3
- Date: 2026-09-30

## Context

Phase 1.2 established explicit ownership and an organization membership boundary. A user can belong to several organizations, so a global user role would leak authority between tenants. Phase 1.3 needs reusable authorization without introducing member administration or speculative ERP capabilities.

## Decision

Roles belong to exactly one organization and are assigned to `OrganizationMembership`, never `User`. A membership can hold multiple roles; permissions combine by union with no deny rules or hierarchy. Role IDs are stable ULIDs, independent of editable names. Names are at most 80 characters. API names are trimmed; PostgreSQL enforces uniqueness on `(organization_id, lower(btrim(name)))`, using the database's case conversion and trimming ordinary outer spaces. Internal spaces remain significant; Unicode normalization and accent folding are not performed. Different organizations can reuse names. A nonempty-name check also protects direct writes.

`PermissionKey` is the application-defined PHP backed enum. It currently contains only `organizations.update` and `roles.view`, both used by implemented policies. `permissions` stores those keys as primary keys, populated by the versioned migration, not an optional development seeder. A check constraint prohibits other keys. Future capabilities require an enum addition and a new migration that updates the check and inserts the key; migration history contains literal frozen values, not a reference to a changing enum. Labels come from the enum. There is no tenant API for defining keys and no permission logic on User.

`role_permission` relates roles to permission keys. Its composite primary key prohibits duplicate grants. `organization_membership_role` relates memberships to roles and repeats `organization_id` so PostgreSQL can enforce two composite foreign keys:

- `(organization_id, organization_membership_id)` references membership `(organization_id, id)`.
- `(organization_id, role_id)` references role `(organization_id, id)`.

These constraints make cross-organization assignments impossible even through direct SQL or a forged pivot tenant ID. Membership/role pairs are unique. Referenced composite keys and supporting indexes are explicit. Links cascade when their role or membership is deleted; organization deletion cascades tenant roles and memberships. Permission deletion is restricted while referenced. These are integrity rules, not public deletion or member-removal workflows. The existing deferred owner-membership foreign key is unchanged.

## Evaluation and ownership

`OrganizationMembership::hasPermission(Organization, PermissionKey)` checks persisted membership within the supplied organization, then grants explicit owners authority or checks whether any assigned role contains the permission. Queries use the supplied tenant and persisted records; loaded relationship collections do not cache grants. Revocation is visible on the next check. The number of queries does not grow with the number of assigned roles, and role lists eager-load permissions.

`organizations.owner_user_id` remains the independent ownership record. Owners need no role assignments and receive every defined capability. No Owner role is created, and naming a role Owner or Administrator confers no special behavior. No default roles are created: none has an immediate assignment workflow. New organization creation remains the existing atomic organization-plus-owner-membership transaction. Existing organizations need no backfill or manual recreation, and the migration never changes ownership or membership data.

`OrganizationPolicy` preserves membership-based viewing and listing of the workspace. Updating organization details requires `organizations.update`; reading role lists and the catalog requires `roles.view`. The owner passes both through membership evaluation. Non-members receive 404; members lacking authority receive 403. Role mutation uses the separate owner-only `manageRoles` policy method, preventing circular bootstrap authorization and self-escalation by a role reader or organization editor. There is no `roles.manage` permission in this phase.

## HTTP and presentation

All RBAC endpoints require authenticated, verified sessions:

| Method and organization-relative path | Authority | Behavior |
| --- | --- | --- |
| `GET roles` | Owner or `roles.view` | Organization roles with eager-loaded permissions; `meta.can_manage` for presentation |
| `GET permissions` | Owner or `roles.view` | Application permission catalog |
| `POST roles` | Owner only | Create role and permission set atomically; 201 |
| `PATCH roles/{role}` | Owner only | Replace submitted name and full permission set atomically; 200 |

Both writes require `name` and a `permissions` array, including an empty array to clear permissions. Unknown or duplicate keys and duplicate names return 422. Scoped route binding rejects a role outside the route organization with 404. Form Requests authorize before validating input; validated fields are explicitly passed to the action. Client IDs and owner references are prohibited. The action also checks role scope, locks existing roles while editing, and converts a database name-uniqueness race into a validation response. Other database failures roll back the full mutation.

Resources expose role `id`, `name`, and sorted permission keys, or permission `key` and `label`. They omit organization IDs, timestamps, ownership fields and pivots. Vue uses local view state for roles and the catalog. Route context controls API URLs, and a generation counter discards late responses from a previous organization. `can_manage` controls presentation only; API authorization is authoritative.

## Consequences and Phase 1.4 boundary

No Redis permission cache is introduced: there is no measured need and invalidation would complicate revocation correctness. The membership API provides a stable place to optimize later. Role lists are deliberately unpaginated for this small administrative surface; pagination can be added when actual scale requires it.

`AssignMembershipRole` is an internal domain action that rejects a mismatched tenant before the database constraint and allows idempotent multiple-role assignments. It is not an HTTP authorization boundary: future callers must authorize membership administration. There is no member-role assignment endpoint, invitation flow, member removal/suspension, ownership transfer, organization deletion, global administrator, audit trail, or ERP module.

Phase 1.4 should introduce an explicitly authorized membership/invitation workflow that calls this action, preserves the owner-membership invariant, and tests cross-tenant denial and privilege escalation. Delegating RBAC administration beyond the owner requires a separate explicit policy decision.
