# Phase 1 — Core platform

**1.2 Organizations and memberships** is the current completed milestone. Later milestones are plans, not implemented capabilities.

| Milestone | Scope | Status |
| --- | --- | --- |
| 1.0 Foundation | Separate applications, Docker environment, health API, automated quality checks, documentation | Complete |
| **1.1 Authentication** | First-party SPA registration, login, recovery, verification, session security, and tests | **Complete** |
| **1.2 Organizations and memberships** | Shared-schema tenant boundary, ownership, membership, onboarding, and isolation tests | **Complete** |
| 1.3 RBAC | Roles, permissions, and authorization policies | Planned; not implemented |
| 1.4 User invitations / organization users | Invitation lifecycle and organization user management | Planned; not implemented |
| 1.5 Audit trail | Record relevant security and domain activity | Planned; not implemented |
| 1.6 Notifications and queues | Notification delivery and asynchronous execution | Planned; not implemented |
| 1.7 Real-time foundation | Authenticated real-time transport and event boundaries | Planned; not implemented |
| 1.8 Security and tenant-isolation hardening | Adversarial isolation tests and security review | Planned; not implemented |

Phase 1.0 established the operational Laravel/Vue shell and quality checks; see its [validation record](phase-01-foundation-validation.md). Phase 1.1 implemented cookie/session authentication; see its [validation record](phase-01-authentication-validation.md). Phase 1.2 establishes organization membership and owner-only organization updates; see its [validation record](phase-01-organizations-validation.md). Full RBAC remains planned for Phase 1.3.

CRM, customers, products, inventory, procurement, sales, invoicing, accounting, HR, payroll, manufacturing, and project management are outside this milestone and have no implementation here. Every future milestone requires explicit instruction before work starts.
