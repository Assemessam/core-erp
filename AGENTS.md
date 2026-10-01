# Working in CoreERP

- Inspect existing code and documentation before editing.
- Keep changes scoped to the requested milestone. Never implement future phases without explicit instruction.
- Keep controllers thin and business/domain logic outside controllers.
- Use Form Requests for non-trivial HTTP validation, API Resources for API representations, and Policies for authorization.
- Once tenancy exists, protect tenant isolation in every data access path and test cross-tenant denial.
- Use database constraints for appropriate invariants. Never use floating-point values for money.
- Add automated tests for important business rules and failure paths.
- Prefer clear, concrete code; avoid unnecessary abstractions and speculative dependencies.
- Keep backend and frontend independent. Pinia is for application/client state, not every API response.
- Run relevant tests and quality checks before finishing; report anything that could not be verified.
- Document significant architectural decisions in `docs/decisions/`.
- Do not commit, push, or change branches unless explicitly asked.

## Architecture guardrails

Follow [ADR 0005](docs/decisions/0005-ddd-modular-monolith-architecture.md). Identity and Organization are the current bounded contexts; keep platform health/readiness outside business modules.

- New business functionality belongs to its owning module; never create future modules before their milestone. Identity owns authentication; Organization owns memberships and tenant RBAC together.
- Domain code must not depend on Laravel, Eloquent, HTTP, Application, or Infrastructure.
- Put mutations behind Application use cases. Controllers adapt HTTP input, invoke Application, and return representations; do not add business database mutations or transactions to controllers.
- Same-module Eloquent is allowed in documented lightweight Application paths. Introduce repositories only for meaningful aggregate persistence; queries may use efficient Eloquent/query-builder/SQL reads.
- Pass actor and organization context explicitly; never use ambient/global tenant state. Tenant scope applies to reads, writes, reports, jobs, exports, and cache keys.
- Keep Laravel Policies as adapters over OrganizationAccess; direct authorized write use cases must also check access. Keep domain invariants separate from actor authorization.
- Organization Application must not depend on Identity Infrastructure; pass authenticated user IDs from trusted HTTP adapters. Identity must not depend on Organization. Organization Eloquent relationships, its Policy, and HTTP actor assertions may reference Identity User where required by Laravel integration.
- Transactions belong in Application orchestration. Do not hide required workflows in model observers.
- Do not introduce domain events without a real consumer, or generic Shared utilities without demonstrated shared semantics.
- Preserve historical migrations and existing HTTP/API behavior during architectural refactors. Add architecture and security tests with boundary changes; static checks do not prove tenant isolation.
