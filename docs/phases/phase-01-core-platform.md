# Phase 1 — Core platform

**1.3 Organization-scoped RBAC** is the latest completed business milestone. **1.3.5 DDD Modular Monolith Refactor** is underway; its architecture/guardrail checkpoint does not complete the refactor. Phase 1.4 remains unimplemented.

| Milestone | Scope | Status |
| --- | --- | --- |
| 1.0 Foundation | Separate applications, Docker environment, health API, automated quality checks, documentation | Complete |
| **1.1 Authentication** | First-party SPA registration, login, recovery, verification, session security, and tests | **Complete** |
| **1.2 Organizations and memberships** | Shared-schema tenant boundary, ownership, membership, onboarding, and isolation tests | **Complete** |
| **1.3 RBAC** | Membership-scoped roles, application permissions, policies, tenant constraints, and role administration | **Complete** |
| **1.3.5 DDD Modular Monolith Refactor** | Establish bounded contexts, pragmatic Application boundaries, CQRS-lite, and architecture guardrails while preserving behavior | **In progress; Organization authorization centralized** |
| 1.4 User invitations / organization users | Invitation lifecycle and organization user management | Planned; not implemented |
| 1.5 Audit trail | Record relevant security and domain activity | Planned; not implemented |
| 1.6 Notifications and queues | Notification delivery and asynchronous execution | Planned; not implemented |
| 1.7 Real-time foundation | Authenticated real-time transport and event boundaries | Planned; not implemented |
| 1.8 Security and tenant-isolation hardening | Adversarial isolation tests and security review | Planned; not implemented |

Phase 1.0 established the operational Laravel/Vue shell and quality checks; see its [validation record](phase-01-foundation-validation.md). Phase 1.1 implemented cookie/session authentication; see its [validation record](phase-01-authentication-validation.md). Phase 1.2 establishes organization membership and owner-only organization updates; see its [validation record](phase-01-organizations-validation.md). Phase 1.3 adds organization-scoped role and permission infrastructure, owner-managed role administration, and permission-based organization updates; see its [validation record](phase-01-rbac-validation.md) and [ADR 0004](../decisions/0004-organization-scoped-rbac.md). Workspace viewing remains membership-based. No default roles or Owner role are needed. Membership-role assignments are proven through the internal domain action and PostgreSQL tests; invitations and user-facing assignment remain Phase 1.4 work.

## Phase 1.3.5 checkpoints

- **1.3.5A — Architecture Guardrails and Characterization: complete.** Documentation, agent rules, architecture suite, HTTP/security and Laravel-wiring characterization are locally validated; see [ADR 0005](../decisions/0005-ddd-modular-monolith-architecture.md) and the [checkpoint record](phase-01-ddd-architecture-validation.md). No namespace or business-logic migration.
- **1.3.5B — Extract Existing Organization Application Entry Points: complete.** Membership-scoped listing and rename now use explicit query/action classes; existing Eloquent binding, Policy/Form Request authorization, API, and schema remain unchanged. See the checkpoint-B section of the [validation record](phase-01-ddd-architecture-validation.md#phase-135b--extract-existing-organization-application-entry-points).
- **1.3.5C — Mechanical Organization Module Namespace Migration: complete.** Existing classes moved into Organization layers, with explicit Policy wiring and active Domain/Application guardrails. No intentional business/API/schema/frontend changes. See the checkpoint-C validation record.
- **1.3.5D — Centralize Organization Authorization: complete.** OrganizationAccess provides fresh persisted decisions shared by Policy adapters and self-authorizing rename/role writes; direct-invocation, Policy parity, and HTTP-denial tests pass.
- **Later 1.3.5 checkpoints:** require separate authorization; not started. Domain invariant extraction and Identity migration remain deferred.

The approved direction keeps Identity separate from Organization, with memberships and tenant RBAC together in Organization. Current lightweight paths retain Eloquent; richer domain aggregates and repositories require actual business invariants. Acceptance is the same business behavior, HTTP API, frontend, database, and tenant/security semantics with different backend architecture.

CRM, customers, products, inventory, procurement, sales, invoicing, accounting, HR, payroll, manufacturing, and project management are outside this milestone and have no implementation here. Every future milestone requires explicit instruction before work starts.
