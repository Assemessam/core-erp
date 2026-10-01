# ADR 0005: DDD-oriented modular monolith with a pragmatic application layer

- Status: Accepted; implemented through Phase 1.3.5G
- Date: 2026-09-30

## Context and compatibility requirement

CoreERP has completed foundation, SPA authentication, organizations/memberships, and organization-scoped RBAC. Global Laravel Models, Actions, Controllers, Requests, Resources, Policies, Enums, and Providers folders are manageable today, but do not establish ownership boundaries for future ERP workflows.

Preserve the existing strengths: Form Requests, minimal Resources, Policies, explicit tenant context, transactional ownership creation, PostgreSQL constraints, and real authorization/isolation tests. Pressure points include controller queries/direct updates, permission evaluation on OrganizationMembership, and actions combining orchestration with HTTP exceptions.

Acceptance: **same business behavior, HTTP API, frontend, database, and tenant/security semantics; different backend architecture**. This ADR amends placement/dependency guidance from ADRs 0003/0004, not their business or security decisions. It does not authorize future milestones.

## Decision and bounded contexts

Adopt a DDD-oriented modular monolith, pragmatic Application layer, and CQRS-lite. This is intentionally not strict Clean Architecture everywhere. Retain one Laravel deployment and the existing shared PostgreSQL database/schema.

| Context | Owns | Does not own |
| --- | --- | --- |
| Identity | User identity, credentials, authentication, password recovery, email verification, current-user representation | Organizations, memberships, tenant RBAC/grants |
| Organization | Organization, ownership, memberships, roles, permission catalog, grants, organization authorization | Credentials and session lifecycle |

Membership and tenant RBAC stay together: roles and memberships belong to an organization, grants connect them, ownership controls role administration, and the owner must remain a member. Splitting them would create circular coordination around these invariants. Organization is not a giant aggregate containing all tenant data.

The two current modules were introduced in authorized migration checkpoints. No CRM, Sales, Inventory, Finance, Accounting, HR, Payroll, Manufacturing, or other future module folders exist. Contexts communicate through intentionally public application entry points and identifiers/results, not foreign aggregate/model internals. Organization's owner/user Eloquent relationships remain narrowly documented infrastructure references to Identity's User; they do not authorize Identity writes.

## Four available layers

Convention: `app/Modules/{Context}/{Domain,Application,Infrastructure,Presentation}`. Create a layer only for actual code. Identity currently has only Infrastructure and Presentation; Organization has all four.

| Layer | Responsibility |
| --- | --- |
| Domain | Business vocabulary, invariants, meaningful value objects, domain exceptions, and rich aggregates/repository contracts when justified; independent of Laravel, Eloquent, HTTP, facades, and transactions |
| Application | Direct write use cases and queries, actor-access coordination, loading/saving, transaction orchestration; independent of HTTP Requests, Resources, Controllers, and responses |
| Infrastructure | Eloquent models, future repositories/mapping, Laravel integrations, providers |
| Presentation | Controllers, Form Requests, Resources, HTTP error translation, future console adapters |

Controllers adapt input, invoke Application, and return representations. Models retain persistence behavior without absorbing workflows. Small Fortify integrations can remain conventional adapters rather than being split into identical pass-through classes.

## Hybrid Eloquent and repository strategy

**Application may use same-module Eloquent models and Laravel database transaction facilities in documented lightweight paths.** This is a deliberate pragmatic exception to strict dependency inversion; Domain remains framework-independent.

Keep current Eloquent models. No duplicate pure User, Organization, Membership, Role, or Permission entities, mappers, generic repositories, or interfaces merely wrapping Eloquent calls. Reference data and permission catalogs suit direct reads.

Introduce a pure domain aggregate when actual state transitions or multi-object invariants need an independently testable consistency boundary: for example sales-order confirmation, inventory reservations, purchase-order approval, invoice posting, manufacturing orders, payroll runs, or a complex membership lifecycle. These are examples, not authorized work.

Introduce repositories for meaningful aggregate loading/persistence and consistency/concurrency needs. Domain repository contracts use domain vocabulary, never query builders. Application-specific technical ports belong in Application; mapping stays private to Infrastructure. No aggregate repository is required now.

Retain PermissionKey as a backed enum. Defer identifier/name/email wrappers without useful invariants. Future Money requires currency, precision, and rounding semantics; never floating-point money.

## CQRS-lite and query architecture

Organize write use cases under `Application/Commands` and reads under `Application/Queries`. Invoke directly, normally using `handle()`. DTO/handler pairs are optional. No command/query bus, event sourcing, separate read database, or asynchronous read-model synchronization.

Queries may use Eloquent, query builder, or SQL without reconstructing aggregates. Allowlist filters/sorting, scope by organization before aggregation/pagination, select deliberate columns, and eager-load appropriately. Typed projections are useful for combined results. Preserve today's unpaginated contracts. Future cross-context reports require reviewed read adapters, not uncontrolled cross-module writes.

## Authorization and tenant isolation

Keep Laravel Policies. OrganizationAccess centralizes persisted Organization decisions in Application; Policies are Laravel adapters and authorized write use cases reuse those decisions. Domain invariants, such as tenant-compatible role assignment and future owner lifecycle protection, remain separate from actor authorization.

Preserve authenticated/verified middleware, authorization before validation, guest 401, unverified 403, non-member 404, and insufficient-authority member 403. Ownership is explicit, never inferred from a role name. Owners need no role; role mutation remains owner-only. Preserve permission unions, fresh persisted checks, next-check revocation, and rejection of spoofed in-memory membership attributes. AssignMembershipRole stays internal, with future callers responsible for membership-administration authorization.

Pass actor and organization context explicitly. Never use an ambient/global active tenant or store it on User/session. Scope resource lookup by organization and resource ID together, including reports, exports, jobs, and cache keys. An identifier/context object is not authorization. Preserve owner membership, foreign keys, and composite cross-tenant constraints; future same-tenant references need appropriate database constraints. No hidden tenant scopes or tenant singleton.

## Transactions and route-model binding

Application owns transaction boundaries. Preserve organization-plus-owner-membership creation, SaveRole's atomic name/grants update and row lock, rollback behavior, and specific duplicate-name conflict handling. A single-row rename needs no ceremonial transaction. Defer a transaction abstraction until a real use case requires it. Repositories/domain objects do not independently commit; required workflows must not hide in model observers. Concurrency semantics remain unchanged.

Keep current Eloquent route-model binding as a Presentation adapter. Preserve parameter names, `scopeBindings()`, and Organization's roles relationship. Binding does not authorize access. Later application entry points may take identifiers without forcing controller bindings to change. Future pure aggregates must not implement route binding.

## Laravel integration

Keep historical migrations, factories, seeders, configuration, and current central route registration in conventional locations. Register module providers explicitly and map OrganizationPolicy to Organization. The User move updated auth configuration, both directions of factory resolution, Fortify bindings, and imports. Existing `App\\` PSR-4 autoloading covers modules; no module loader is needed. Keep platform health/readiness outside business contexts.

## Events and Shared Kernel policy

Do not add domain events, event sourcing, a Shared Kernel directory, generic base services, or an application bus. A domain event needs a real consumer of a business fact and stays framework-independent. Laravel application/integration events are delivery adapters. Required invariants remain synchronous inside the initiating transaction; external effects happen after commit. Reliable delivery infrastructure needs a demonstrated requirement. Preserve existing authentication framework events/notifications.

Shared code requires multiple real consumers, identical semantics, narrow stable responsibility, clear ownership, and no dependencies back into modules. A shared technical transaction port is not automatically a DDD Shared Kernel. Shared must not become a home for unrelated utilities, User, Organization, or RBAC.

## Dependency rules

Strict direction: `Presentation → Application → Domain`; Infrastructure implements Domain/Application contracts; providers/bootstrap compose concrete adapters.

| Source | Allowed | Forbidden |
| --- | --- | --- |
| Domain | PHP, own domain types, approved shared domain vocabulary | Laravel/Eloquent/HTTP, Application, Infrastructure, Presentation, foreign module internals |
| Application | Own Domain/Application; same-module Eloquent/DB in lightweight paths | HTTP delivery classes/responses, `abort()`, ambient request/auth/tenant state |
| Presentation | Own Application, HTTP/Gate, own Eloquent types for binding/Resources | Business mutation, transaction control, workflow orchestration |
| Infrastructure | Framework facilities, own Domain/Application contracts | Workflows hidden in models/observers, duplicated access rules |
| Policy adapters | Application authorization decisions and Laravel authorization types | Independent duplicate permission rules |
| Composition | Explicit provider/adapter wiring | Business workflows |

Cross-module application code uses intentionally public application contracts, not foreign Eloquent models. Document narrow infrastructure exceptions. Add rules progressively during migration rather than pretending existing legacy code already follows all target boundaries.

In the implemented contexts, Organization Application receives the authenticated owner/user ID as a scalar and has no Identity import. Five Organization adapters reference Identity's Eloquent User: the Organization and OrganizationMembership persistence relationships, the Laravel OrganizationPolicy actor type, and the two HTTP controllers' authenticated-user type assertions. These are explicit persistence/framework edges; Identity has no Organization dependency. Fortify remains Identity Infrastructure integration, while its HTTP responses and current-user resource live in Identity Presentation. Identity needs no Domain or Application layer yet.

## Testing and enforcement

Use the installed Pest architecture plugin, without another architecture package. PHPUnit discovers `tests/Architecture` in normal `composer test`/`composer quality`. Architecture tests do not boot Laravel or touch a database.

Active guardrails reject direct database facade/manager/connection dependencies and explicit transaction-control calls in controllers; HTTP dependencies in current models; ambient session/request dependencies in current business actions/models/policies/enums; and PHP `global`/`$GLOBALS` under app. Syntax checks use the installed PHP parser rather than regex.

Domain/Application tests discover conventional module layer directories and assert the current Organization layers exist. Domain rejects framework/outer dependencies; Application rejects HTTP delivery, ambient helpers, and Identity dependencies. No Identity layer stubs are required.

These checks are not data-flow analysis. They cannot prove tenant predicates, identify dynamic transaction calls, rule out every singleton/session/cache tenant resolver, or detect every indirect mutation. Reviews and integration/security tests remain necessary. Extend cross-module rules with each future boundary change.

| Test level | Purpose | Database |
| --- | --- | --- |
| Domain unit | Invariants and future state transitions | None |
| Application | Authorization, orchestration, rollback | PostgreSQL for current Eloquent paths |
| Infrastructure | Constraints, future mapping and concurrency | Real PostgreSQL |
| Feature/API | Middleware, binding, Policies, denial order, status/JSON contracts | Real PostgreSQL |
| E2E | Vue/API authentication, onboarding/isolation, RBAC | Established full stack |

Do not mock away permission evaluation or constraints. Preserve existing tests and add focused characterization before moves. Assert application-owned messages, resource fields/statuses, sorted grants, and `meta.can_manage`; document incidental framework error wording rather than freezing PHP namespaces as public contracts.

Current Feature tests use DatabaseTransactions and the configured PostgreSQL database, with no dedicated database name in phpunit.xml. A migration compatibility test drops/reapplies RBAC tables inside its outer transaction; constraint tests use savepoints and force deferred checks. Do not run database tests in parallel or concurrently with browser writes. Do not change this strategy as architectural cleanup. Review isolation before future parallelization or dedicated database setup.

## Alternatives, trade-offs, and consequences

- Global Laravel folders remain cheap but do not establish growing capability ownership.
- Feature-only folders are simpler but provide fewer explicit workflow boundaries.
- Pure entities/repositories everywhere add duplicate state, mapping drift, and unnecessary cost.
- Separating memberships from RBAC creates needless cross-context coordination.
- Distributed CQRS, event sourcing, module discovery packages, and generic repository frameworks have no current requirement.

The chosen architecture improves ownership, reviewability, and domain testing where valuable. It accepts Laravel coupling in simple Application paths and requires documented exceptions. Namespace moves risk policy/factory/provider discovery, Resource 201 inference, and framework error wording. No schema/API/frontend/permission/pagination/concurrency change is authorized by this decision alone.

## Revisit triggers and rollout

Revisit when real workflows need rich aggregates, aggregate persistence repeats, concurrency requirements change, multiple contexts share semantics, events gain consumers, reliable delivery is needed, or dependency rules become difficult to express in Pest.

Phase 1.3.5 was implemented through separately authorized checkpoints A–G. Phase 1.4 remains unimplemented.
