# ADR 0003: Shared-schema tenancy and organizations

- Status: Accepted for Phase 1.2
- Date: 2026-09-30

## Context

CoreERP needs a tenant identity before any ERP records exist. Users may belong to multiple organizations, and a URL may expose an organization identifier. Authentication alone cannot grant access to an organization.

## Decision

Use one PostgreSQL database and one shared schema. Future tenant-owned tables will carry an explicit `organization_id` foreign key and queries must scope by that key after server-side membership and authorization checks. This keeps the boundary visible in application code and supports cross-organization membership. Database-per-tenant would add provisioning, migration, connection, and operational complexity before there is a need for physical isolation.

`organizations.id` is a ULID primary key exposed in API responses and `/app/organizations/{organizationId}` routes. ULIDs avoid sequential public identifiers and integrate with Eloquent's `HasUlids`; they are not an authorization mechanism. `organizations.owner_user_id` is an explicit user foreign key. Ownership is a domain property, not an RBAC role. A separate `organization_memberships` model connects users and organizations. All memberships are active because invitations, disabling, and removal are outside Phase 1.2. A unique `(organization_id, user_id)` constraint prevents duplicates. Foreign keys protect references, and a deferred composite foreign key from `(organizations.id, organizations.owner_user_id)` to the matching membership ensures the owner remains a member. Creation inserts both records in one transaction; the deferred check permits this circular invariant at commit.

The SPA derives current organization from its route. No `users.current_organization_id` or server-side active-tenant session is stored: a user can navigate or bookmark each organization's workspace directly. Each backend request independently authenticates and authorizes the actor. The list is scoped to memberships. Laravel route-model binding resolves a ULID, and `OrganizationPolicy` returns 404 for a non-member on show or update, including a real but unrelated ID. An ordinary member can view and gets 403 on owner-only updates. The owner can update the organization name. All organization APIs require a verified email in addition to authentication; `/api/v1/me` remains accessible to unverified users.

## Consequences and boundaries

Phase 1.2 establishes tenant identity, membership, and a narrow owner-only organization setting. It does not establish roles, permissions, invitations, member administration, or authorization rules for future ERP modules. Those modules must add explicit organization foreign keys, server-side scopes and policies, and cross-tenant denial tests. The route ID is context supplied by the client and can never substitute for those checks.

The composite owner-membership foreign key uses PostgreSQL's deferred constraint support; this application already targets PostgreSQL. Organization deletion and membership removal are deliberately absent. Any future membership lifecycle must preserve or deliberately revise the owner invariant and its database constraint.
