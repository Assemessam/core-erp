# Phase 1.4 — Organization Users validation

Date: 2026-10-01. Status: implemented locally, awaiting review and commit. Branch: `feature/organization-users`. No commit, push, branch switch, reset or discarded work. Phase 1.5 remains unimplemented.

## Inspection and baseline

Started with a clean working tree on the expected branch. Read root AGENTS.md, README, ADRs 0001–0005, system overview, Phase 1 roadmap and the final Phase 1.3.5 validation record. Inspected both modules, all Organization layers, authorization evaluator/decision/Policy, membership/role/permission models and invariants, all migrations/constraints, authentication/verification/reset/mail configuration, SPA router/auth flows and Architecture tests before editing.

Before changes, `docker compose exec -T backend composer quality` passed **106 tests / 893 assertions**, Pint (81 files) and Larastan level 8. Frontend `npm run quality` passed **7 Vitest files / 28 tests**, lint/format/TypeScript/build. The pinned Playwright suite passed **3 tests**. Baseline and final backend database tests ran sequentially, separate from browser writes.

The runtime stopped between conversation continuations; the existing Compose services were restarted with `docker compose up -d --wait`, and `php artisan migrate --no-interaction` reported nothing left to migrate. Repository changes were retained. No environment files or dependency locks were changed.

## Implemented behavior and boundaries

[ADR 0006](../decisions/0006-organization-membership-lifecycle-and-invitations.md) is the full design record. Organization Domain now owns active/suspended vocabulary, owner membership protection, and verified email-bound invitation lifecycle rules. Application owns authorized explicit commands, tenant scoping, locking and transactions; Eloquent and SMTP remain Infrastructure. Controllers are HTTP adapters. No repositories, queue, notification center, ownership transfer, deletion endpoint, audit trail or future ERP modules were added.

Suspension retains role grants and removes active organization/RBAC access; reactivation restores those grants; removal deletes only membership and its cascaded grant links. The original owner FK is preserved and a second deferred active-owner composite FK strengthens it. The migration safely defaults existing membership to active.

Invitations persist ULID, organization, normalized email, inviter, seven-day expiration, state and SHA-256 token hash. Pending/accepted/revoked are stored; expired is derived. A state-based partial unique pending-email index avoids time-dependent predicates. Reinvite atomically revokes the old pending credential. Acceptance locks organization then invitation, validates credential and verified email identity, creates membership and selected role grants, and marks accepted in one transaction. Replay fails and cannot duplicate or recreate membership.

The only new permission keys are `members.view` and `members.invite`. Lifecycle/member-role management and invitation role selection are owner-only. Delegated inviters issue roleless invitations and cannot replace/revoke role-bearing invitations. All nested resources and roles are tenant-scoped; PostgreSQL composite constraints reject direct corruption.

SPA Users and acceptance routes are implemented. The invitation URL uses a fragment, captured only in memory and removed by router replacement. Login/register/verification reuse Identity. The original invitation email is the recovery path after a full reload. Expected terminal states, wrong identity, authorization failures and stale tenant responses have coverage.

SMTP sends after commit, through a resolved transport restricted to Symfony SMTP. InvitationDelivery obtains the actual named Laravel mailer with `Mail::driver()` and checks `getSymfonyTransport()` before constructing or sending the email; raw configuration cannot bypass the guard through a mailer URL override. PHP's development `zend.exception_ignore_args=0` prompted a specific review: transport/view exceptions can carry body bytes. The adapter throws a sanitized Application exception without retaining the original exception; HTTP 503 explains that the saved invitation should be reinvited. Tests assert that neither the token nor a chained transport exception survives. No credential-bearing failure text is intentionally logged.

## Initial implementation test results

The final complete `composer quality` run passed **144 tests / 1,167 assertions**, with no skips. The following counts are partitions of that complete run, not claims of additional separate executions:

| Suite / area | Passed tests |
| --- | ---: |
| Unit / pure Organization Domain | 12 |
| Architecture | 14 |
| Organization Application | 44 |
| HTTP architecture characterization | 11 |
| Authentication / verification / password reset | 13 |
| Existing Organizations | 8 |
| Existing RBAC | 12 |
| Phase 1.4 HTTP/security | 18 |
| Phase 1.4 PostgreSQL integrity | 4 |
| Health | 6 |
| Integration: real acceptance concurrency + readiness | 2 |
| Total | 144 |

Coverage includes guest/unverified/non-member/unauthorized denial, normalized email, hashed credentials and safe resources, active/suspended duplicate membership, reinvite and repeated revoke, matching/wrong/spoofed identity, invalid/expired/revoked/replayed tokens, transactional rollback after final state mutation, failed role replacement/reinvite rollback, tenant mismatch even for an owner of both tenants, retained-grant suspension/reactivation, owner protection, direct Application authorization, allowlisted permissions, raw foreign/duplicate/cross-tenant corruption, intentional cascades and migration backfill.

The acceptance concurrency integration test spawns two independent PHP processes and PostgreSQL sessions. It observes both sessions waiting on database locks before releasing the parent lock; outcomes are one accepted and one rejected-as-accepted, with exactly one membership. It clears PostgreSQL statistics snapshots while polling and cleans only its own committed fixtures. This supplements repeated HTTP acceptance tests.

Architecture tests automatically include new Domain/Application/controller classes. Existing rules remain intact; only the two new authenticated HTTP actor controllers were added to the precise Identity User adapter allowlist. Domain is still framework-independent; Application has no HTTP/Presentation/Policy/Gate/ambient auth or Identity Infrastructure dependency; controllers contain no workflow persistence/transactions; Identity has no Organization dependency.

Frontend final quality passed **9 Vitest files / 45 tests**, TypeScript, ESLint, Prettier and production build (122 modules). New tests cover member/invitation display, invitation/member role selection, lifecycle commands, owner/delegation controls, revoke/reinvite, stale responses, error rendering, guest/unverified/verified acceptance, wrong-email/expired/revoked/accepted/invalid states, token cleanup and return navigation.

Full Playwright passed **4 tests**, including all three previous scenarios and the new real-Mailpit flow: owner registers/verifies/creates organization and role; invites a new email with the role; invitee registers/verifies/accepts; granted member-list authority survives refresh; suspension hides access; reactivation restores it; removal removes access without deleting the account.

During implementation, tests found and resolved the exception-map-versus-render adapter mistake, deferred trigger handling in migration reapply tests, a frontend authorization message omission, and the test helper's verification subject mismatch. No failing gate remains.

## Commands and quality gates

Commands were executed from the repository root. Read/inspection commands also included `pwd`, `git status --short`, `git branch --show-current`, `rg --files`, `rg`, `cat`, `sed`, `git diff` and targeted local vendor inspection. Temporary implementation/log helpers lived outside the repository.

| Command | Final result |
| --- | --- |
| `docker compose exec -T backend composer quality` | 144 tests / 1,167 assertions; Pint 116 files; Larastan level 8, 79 application files, no errors |
| `docker compose exec -T backend composer test` | Previous-suite regression run during implementation; final all-suite result is included in quality above |
| `docker compose exec -T backend php vendor/bin/pest tests/Feature/OrganizationUsersTest.php tests/Unit/Organization/MembershipLifecycleTest.php --compact` | Focused iteration; final coverage passes in complete suite |
| `docker compose exec -T backend composer format` | Applied Pint formatting; final lint passed |
| `docker compose exec -T backend composer analyse` | Used during implementation; final level 8 analysis passed in quality |
| `docker compose exec -T backend composer dump-autoload --optimize --strict-psr` | Passed, 9,079 classes |
| `docker compose exec -T backend composer validate --strict` | Valid |
| `docker compose exec -T backend composer check-platform-reqs` | All requirements passed |
| `docker compose exec -T backend composer audit` | No security advisories |
| `docker compose exec -T frontend npm run format` | Applied Prettier; final check passed |
| `docker compose exec -T frontend npm run quality` | ESLint, Prettier, TypeScript, 45 Vitest tests and production build passed |
| `docker compose exec -T frontend npm audit` | Zero vulnerabilities |
| `docker run --rm --network host --ipc=host -v "$PWD/frontend:/app" -w /app -e CI=1 mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test` | 4 passed |
| `docker compose config --quiet` | Valid |
| `docker compose up -d --wait` / `docker compose ps` | Backend, frontend, PostgreSQL, Redis and Mailpit healthy |
| `docker compose exec -T postgres pg_isready -U coreerp -d coreerp` | Accepting connections |
| `docker compose exec -T redis redis-cli ping` | PONG |
| `curl -fsS http://localhost:8088/api/v1/health` | HTTP 200, status ok |
| `curl -fsS http://localhost:8088/api/v1/ready` | HTTP 200, status ok |
| `curl -fsS http://localhost:5174/api/v1/ready` | Proxy HTTP 200, status ok |
| `curl -s -o /dev/null -w 'Mailpit HTTP %{http_code}\n' http://localhost:8026` | HTTP 200 |
| `docker compose exec -T backend php artisan migrate --no-interaction` | New migration applied; subsequent check found nothing pending |
| `docker compose exec -T backend php artisan migrate:status` | All four migrations Ran |
| `docker compose exec -T backend php artisan route:list --path=api` / `--path=api -vv` | 21 API routes; business auth/verified middleware and invitation throttles inspected |
| `git diff --check` | Passed |

No historical migration was edited. The RBAC migration compatibility test now rolls back/reapplies the dependent new migration in the correct order, flushes deferred checks before DDL, and still asserts the earlier ownership behavior. Existing catalog assertions were extended for the two new permissions.

## Security findings, limitations and manual review

No unresolved cross-tenant access, identity-binding, owner-protection or replay failure was observed in these checks. Tests are evidence, not proof of all possible attacks. Review especially:

- The added active-owner deferred FK and intentional invitation/member pivot cascades.
- Explicit trusted actor identity inputs to AcceptInvitation and the narrow seven-adapter Identity User allowlist.
- Organization-first locking and the coarse tenant serialization tradeoff.
- Owner-only role/lifecycle authority and restrictions on replacing role-bearing invitations.
- SMTP failure sanitization and the deliberate after-commit delivery/reinvite behavior.
- Memory-only token cleanup and reopening the email after reload/same-tab verification.

Known limits: lists remain unpaginated; SMTP has no durable outbox/retry queue; pending expired invitations are retained for reinvite; earlier requests already authorized can finish during suspension; the configured development database is shared by sequential tests; browser tests leave their usual local accounts/messages. Existing cross-origin CORS method support and differing framework/policy 404 body text remain documented deployment/API follow-ups. No production deployment, external SMTP provider or exhaustive load testing was performed. All requested local quality gates were executable.

Recommendation for Phase 1.5: after reviewing/committing Phase 1.4, separately scope tenant-bound audit records for invitation and membership/role commands, with actor/outcome context, retention/access rules and strict exclusion of credentials, hashes and mail content. No Phase 1.5 code is included.

## F1 security correction — effective transport validation

The independent review found that Laravel applies a mailer URL after the previous raw `transport` check. With the named/raw mailer set to SMTP and `MAIL_URL=log://localhost`, Laravel resolves LogTransport and could log the invitation URL. Only F1 was authorized for this correction. F2, F3, F4 and Phase 1.5 remain untouched.

InvitationDelivery now resolves the actual mailer through the installed Laravel version's public `Mail::driver()` API, inspects its public `Mailer::getSymfonyTransport()` accessor, and sends through that same resolved instance only if it is a Symfony SmtpTransport (including ESMTP). Validation precedes URL/Mailable construction. No environment-string heuristic, reflection, configuration dump or token-bearing exception is used. Existing sanitized failures and HTTP contracts remain unchanged.

The existing invitation policy is SMTP-only. LogTransport logs rendered mail; ArrayTransport retains debugging messages. Failover and round-robin can delegate to logging children, and neither composite was enabled for invitations. Both are rejected as effective non-SMTP transports without speculative child traversal. General production-capable mail configuration is unchanged; no non-SMTP production transport was previously supported for invitations.

`tests/Feature/InvitationDeliveryTest.php` resolves real framework mailers, without Mail::fake(). The exact regression warms the cached SMTP instance, changes its URL to `log://localhost`, calls the supported `Mail::purge('smtp')`, and explicitly verifies the newly resolved LogTransport while the raw transport remains SMTP. Calling the actual InvitationDelivery then produces the safe exception with no token or chained exception. Assertions show no MessageSending/MessageSent event and no logging call. Array URL override coverage additionally checks that no message is retained. Direct log selection is still covered, moved from the Application test file into these real-transport tests. Resolved array, failover and round-robin rejection are also covered. The URL-log and URL-array tests failed against the original adapter and pass with the correction.

The two existing Mail::fake()-based invitation suites set their test-only default mailer to SMTP because MailFake forwards the transport accessor to its underlying manager's default mailer. Their delivery interception remains in place. The real-transport regression does not use this fake or depend on the default mailer, avoiding a cached/fake SMTP false positive. The existing exception-sanitization test follows the corrected driver-resolution call.

Final correction validation:

| Command / gate | Result |
| --- | --- |
| Focused Pest: InvitationDeliveryTest, Application/MembershipLifecycleTest, OrganizationUsersTest | 31 passed / 250 assertions |
| `docker compose exec -T backend composer quality` | 149 passed / 1,218 assertions, no skips; Pint 117 files; Larastan level 8, 79 application files, no errors |
| Complete-suite partitions | Domain 12; Architecture 14; Application 43; HTTP characterization 11; Auth 13; Organizations 8; RBAC 12; Phase 1.4 HTTP 18; PostgreSQL integrity 4; Health 6; Integration 2; effective delivery transport 6 |
| Real PostgreSQL acceptance concurrency | Passed within the complete suite; backend tests completed before browser writes |
| Optimized Composer autoload with `--strict-psr` | Passed, 9,079 classes |
| Composer validate `--strict`, platform check, audit | Valid; all requirements passed; no advisories |
| Frontend `npm run quality` and `npm audit` | 9 files / 45 Vitest tests; TypeScript, ESLint, Prettier and build passed; zero vulnerabilities |
| Full pinned Playwright suite | 4 passed, including real Mailpit invitation delivery and the full multi-user lifecycle |
| Compose config/status | Valid; all five services healthy |
| Mailpit info, API readiness, SPA proxy readiness | HTTP 200; API/proxy status ok |
| PostgreSQL / Redis | Accepting connections / PONG |
| Migration status | All four migrations Ran; no new migration or permanent schema change in this correction |
| Git whitespace and scope checks | `git diff --check` passed; frontend/migration file hashes unchanged from the correction's starting state; nothing staged |

Counts above are partitions of the complete run, not additional separate executions. The Application count decreases by one because the existing direct log test moved to the new six-case transport suite; total coverage increases by five tests. Normal SMTP delivery is proven by the passing browser flow reading the real invitation email from Mailpit. No invitation credential reaches a logging transport in the tested rejected paths.

Correction files: InvitationDelivery.php; the two existing invitation test files; new InvitationDeliveryTest.php; ADR 0006's mail paragraph; and this validation record. No configuration, Application workflow, API, migration or frontend source changed. The full uncommitted Phase 1.4 frontend diff remains present; the correction adds no frontend diff. `git diff -- backend/database/migrations` remains empty and the existing untracked lifecycle migration is unchanged. SMTP still has no durable retry/outbox, and alternative production transports/composites require a separately reviewed policy if enabled later. F1 is resolved for the installed framework and current supported invitation delivery path.

## File inventory

18 tracked files modified; 46 new files including the F1 transport regression file. No files staged. Plain `git diff --stat` excludes untracked files.

Modified:

- `README.md`
- `backend/app/Modules/Organization/Application/Authorization/OrganizationAccess.php`
- `backend/app/Modules/Organization/Application/Queries/ListOrganizations.php`
- `backend/app/Modules/Organization/Domain/Authorization/PermissionKey.php`
- `backend/app/Modules/Organization/Infrastructure/Authorization/OrganizationPolicy.php`
- `backend/app/Modules/Organization/Infrastructure/Eloquent/Models/Organization.php`
- `backend/app/Modules/Organization/Infrastructure/Eloquent/Models/OrganizationMembership.php`
- `backend/app/Modules/Organization/Presentation/Http/Exceptions/OrganizationFailureMapper.php`
- `backend/bootstrap/app.php`
- `backend/routes/api.php`
- `backend/tests/Architecture/BoundariesTest.php`
- `backend/tests/Feature/RbacTest.php`
- `docs/architecture/system-overview.md`
- `docs/phases/phase-01-core-platform.md`
- `frontend/src/lib/httpError.ts`
- `frontend/src/router/index.ts`
- `frontend/src/views/OrganizationWorkspaceView.vue`
- `frontend/src/views/VerificationRequiredView.vue`

Created:

- `backend/app/Modules/Organization/Application/Commands/AcceptInvitation.php`
- `backend/app/Modules/Organization/Application/Commands/ActivateMembership.php`
- `backend/app/Modules/Organization/Application/Commands/CreateInvitation.php`
- `backend/app/Modules/Organization/Application/Commands/RemoveMembership.php`
- `backend/app/Modules/Organization/Application/Commands/RevokeInvitation.php`
- `backend/app/Modules/Organization/Application/Commands/SuspendMembership.php`
- `backend/app/Modules/Organization/Application/Commands/SyncMembershipRoles.php`
- `backend/app/Modules/Organization/Application/Exceptions/InvitationDeliveryFailed.php`
- `backend/app/Modules/Organization/Application/Operations/LockManagedMembership.php`
- `backend/app/Modules/Organization/Application/Operations/ResolveOrganizationRoles.php`
- `backend/app/Modules/Organization/Domain/Invitations/InvitationRejected.php`
- `backend/app/Modules/Organization/Domain/Invitations/InvitationRules.php`
- `backend/app/Modules/Organization/Domain/Invitations/InvitationState.php`
- `backend/app/Modules/Organization/Domain/Memberships/MembershipRules.php`
- `backend/app/Modules/Organization/Domain/Memberships/MembershipStatus.php`
- `backend/app/Modules/Organization/Domain/Memberships/OwnerMembershipProtected.php`
- `backend/app/Modules/Organization/Infrastructure/Eloquent/Models/OrganizationInvitation.php`
- `backend/app/Modules/Organization/Infrastructure/Mail/InvitationDelivery.php`
- `backend/app/Modules/Organization/Infrastructure/Mail/OrganizationInvitationMail.php`
- `backend/app/Modules/Organization/Presentation/Http/Controllers/OrganizationInvitationController.php`
- `backend/app/Modules/Organization/Presentation/Http/Controllers/OrganizationMemberController.php`
- `backend/app/Modules/Organization/Presentation/Http/Controllers/OrganizationUsersAccessController.php`
- `backend/app/Modules/Organization/Presentation/Http/Requests/AcceptInvitationRequest.php`
- `backend/app/Modules/Organization/Presentation/Http/Requests/InviteMemberRequest.php`
- `backend/app/Modules/Organization/Presentation/Http/Requests/SyncMembershipRolesRequest.php`
- `backend/app/Modules/Organization/Presentation/Http/Resources/InvitationResource.php`
- `backend/app/Modules/Organization/Presentation/Http/Resources/MembershipResource.php`
- `backend/config/organization.php`
- `backend/database/migrations/2026_10_01_000001_add_membership_lifecycle_and_invitations.php`
- `backend/resources/views/mail/organization-invitation.blade.php`
- `backend/tests/Feature/Application/MembershipLifecycleTest.php`
- `backend/tests/Feature/InvitationDeliveryTest.php`
- `backend/tests/Feature/OrganizationUsersIntegrityTest.php`
- `backend/tests/Feature/OrganizationUsersTest.php`
- `backend/tests/Integration/InvitationConcurrencyTest.php`
- `backend/tests/Support/accept-invitation-worker.php`
- `backend/tests/Unit/Organization/MembershipLifecycleTest.php`
- `docs/decisions/0006-organization-membership-lifecycle-and-invitations.md`
- `docs/phases/phase-01-organization-users-validation.md`
- `frontend/e2e/organization-users.spec.ts`
- `frontend/src/__tests__/AcceptInvitation.test.ts`
- `frontend/src/__tests__/OrganizationUsers.test.ts`
- `frontend/src/lib/organizationUsers.ts`
- `frontend/src/lib/pendingInvitation.ts`
- `frontend/src/views/AcceptInvitationView.vue`
- `frontend/src/views/OrganizationUsersView.vue`

## Final Git output

`git status --short`:

```text
 M README.md
 M backend/app/Modules/Organization/Application/Authorization/OrganizationAccess.php
 M backend/app/Modules/Organization/Application/Queries/ListOrganizations.php
 M backend/app/Modules/Organization/Domain/Authorization/PermissionKey.php
 M backend/app/Modules/Organization/Infrastructure/Authorization/OrganizationPolicy.php
 M backend/app/Modules/Organization/Infrastructure/Eloquent/Models/Organization.php
 M backend/app/Modules/Organization/Infrastructure/Eloquent/Models/OrganizationMembership.php
 M backend/app/Modules/Organization/Presentation/Http/Exceptions/OrganizationFailureMapper.php
 M backend/bootstrap/app.php
 M backend/routes/api.php
 M backend/tests/Architecture/BoundariesTest.php
 M backend/tests/Feature/RbacTest.php
 M docs/architecture/system-overview.md
 M docs/phases/phase-01-core-platform.md
 M frontend/src/lib/httpError.ts
 M frontend/src/router/index.ts
 M frontend/src/views/OrganizationWorkspaceView.vue
 M frontend/src/views/VerificationRequiredView.vue
?? backend/app/Modules/Organization/Application/Commands/AcceptInvitation.php
?? backend/app/Modules/Organization/Application/Commands/ActivateMembership.php
?? backend/app/Modules/Organization/Application/Commands/CreateInvitation.php
?? backend/app/Modules/Organization/Application/Commands/RemoveMembership.php
?? backend/app/Modules/Organization/Application/Commands/RevokeInvitation.php
?? backend/app/Modules/Organization/Application/Commands/SuspendMembership.php
?? backend/app/Modules/Organization/Application/Commands/SyncMembershipRoles.php
?? backend/app/Modules/Organization/Application/Exceptions/InvitationDeliveryFailed.php
?? backend/app/Modules/Organization/Application/Operations/LockManagedMembership.php
?? backend/app/Modules/Organization/Application/Operations/ResolveOrganizationRoles.php
?? backend/app/Modules/Organization/Domain/Invitations/
?? backend/app/Modules/Organization/Domain/Memberships/MembershipRules.php
?? backend/app/Modules/Organization/Domain/Memberships/MembershipStatus.php
?? backend/app/Modules/Organization/Domain/Memberships/OwnerMembershipProtected.php
?? backend/app/Modules/Organization/Infrastructure/Eloquent/Models/OrganizationInvitation.php
?? backend/app/Modules/Organization/Infrastructure/Mail/
?? backend/app/Modules/Organization/Presentation/Http/Controllers/OrganizationInvitationController.php
?? backend/app/Modules/Organization/Presentation/Http/Controllers/OrganizationMemberController.php
?? backend/app/Modules/Organization/Presentation/Http/Controllers/OrganizationUsersAccessController.php
?? backend/app/Modules/Organization/Presentation/Http/Requests/AcceptInvitationRequest.php
?? backend/app/Modules/Organization/Presentation/Http/Requests/InviteMemberRequest.php
?? backend/app/Modules/Organization/Presentation/Http/Requests/SyncMembershipRolesRequest.php
?? backend/app/Modules/Organization/Presentation/Http/Resources/InvitationResource.php
?? backend/app/Modules/Organization/Presentation/Http/Resources/MembershipResource.php
?? backend/config/organization.php
?? backend/database/migrations/2026_10_01_000001_add_membership_lifecycle_and_invitations.php
?? backend/resources/
?? backend/tests/Feature/Application/MembershipLifecycleTest.php
?? backend/tests/Feature/InvitationDeliveryTest.php
?? backend/tests/Feature/OrganizationUsersIntegrityTest.php
?? backend/tests/Feature/OrganizationUsersTest.php
?? backend/tests/Integration/InvitationConcurrencyTest.php
?? backend/tests/Support/
?? backend/tests/Unit/Organization/MembershipLifecycleTest.php
?? docs/decisions/0006-organization-membership-lifecycle-and-invitations.md
?? docs/phases/phase-01-organization-users-validation.md
?? frontend/e2e/organization-users.spec.ts
?? frontend/src/__tests__/AcceptInvitation.test.ts
?? frontend/src/__tests__/OrganizationUsers.test.ts
?? frontend/src/lib/organizationUsers.ts
?? frontend/src/lib/pendingInvitation.ts
?? frontend/src/views/AcceptInvitationView.vue
?? frontend/src/views/OrganizationUsersView.vue
```

`git diff --stat` (tracked changes only; new files remain untracked):

```text
 README.md                                          |  16 ++-
 .../Authorization/OrganizationAccess.php           |  27 +++-
 .../Application/Queries/ListOrganizations.php      |   3 +-
 .../Domain/Authorization/PermissionKey.php         |   4 +
 .../Authorization/OrganizationPolicy.php           |  15 +++
 .../Eloquent/Models/Organization.php               |   6 +
 .../Eloquent/Models/OrganizationMembership.php     |  14 +-
 .../Http/Exceptions/OrganizationFailureMapper.php  |  19 +++
 backend/bootstrap/app.php                          |   7 +
 backend/routes/api.php                             |  13 ++
 backend/tests/Architecture/BoundariesTest.php      |   4 +
 backend/tests/Feature/RbacTest.php                 |   9 +-
 docs/architecture/system-overview.md               | 148 ++++++++-------------
 docs/phases/phase-01-core-platform.md              |   6 +-
 frontend/src/lib/httpError.ts                      |  10 ++
 frontend/src/router/index.ts                       |  22 +++
 frontend/src/views/OrganizationWorkspaceView.vue   |   8 ++
 frontend/src/views/VerificationRequiredView.vue    |   4 +-
 18 files changed, 229 insertions(+), 106 deletions(-)
```
