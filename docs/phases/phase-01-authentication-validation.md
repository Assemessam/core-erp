# Phase 1.1 — Authentication validation

Date: 2026-09-30. Scope: first-party SPA authentication only. Validation used the local Docker Compose stack on `localhost:5174` (SPA), `localhost:8088` (Laravel), and `localhost:8026` (Mailpit).

## Before implementation

The foundation repository had four services, a Vite proxy for `/api`, Sanctum installed with its CSRF route disabled, file sessions, a `User` scaffold, and no authentication flows. Before editing, `docker compose exec -T backend composer quality` passed 7 tests plus Pint and Larastan; `docker compose exec -T frontend npm run quality` passed 3 tests plus ESLint, Prettier, type checking, and build. The repository has no Git commits, so the entire foundation is untracked; no existing work was reset.

## Implemented and checked

- Fortify provides registration, session login/logout, password recovery, signed email verification, and resend. Registration creates only a user. The email is normalized before validation and persistence, and registration/reset share a 12-character letters-and-numbers password rule.
- Sanctum stateful API middleware reads the `web` session for `GET /api/v1/me`. Its JSON resource exposes only ID, name, email, and verification status. Guests receive 401; unverified users can read their own verification status.
- The SPA bootstraps CSRF, sends credentialed Axios requests, and initializes authentication from `/api/v1/me` before guarded navigation. The Pinia store contains no tokens or session IDs. The browser session cookie is HttpOnly; the XSRF cookie is readable only so Axios can echo its CSRF value.
- Redis stores encrypted server-side sessions. PostgreSQL stores existing users and password-reset-token records. No migration or Sanctum personal-access-token flow was added.
- Mailpit receives local verification/reset email. The Vite proxy serves the SPA's GET auth pages while forwarding auth mutations, `/sanctum`, and `/api` to Laravel.

## Results

| Check | Command or observation | Result |
| --- | --- | --- |
| Backend suite | `docker compose exec -T backend composer quality` | 20 tests, 95 assertions; Pint passed 41 files; Larastan/PHPStan passed 19 analyzed files with no errors |
| Composer metadata and dependencies | `composer validate --strict`; `composer audit` inside backend container | Valid; no advisories |
| Locked frontend install and PHP platform | `docker compose exec -T frontend npm ci`; `docker compose exec -T backend composer check-platform-reqs` | 288 npm packages installed with no vulnerabilities; all PHP platform requirements satisfied |
| Frontend suite | `docker compose exec -T frontend npm run quality` | ESLint and Prettier passed; `vue-tsc` passed; 14 Vitest tests passed; Vite production build passed |
| npm dependencies | `docker compose exec -T frontend npm audit --audit-level=low` | 0 vulnerabilities |
| Browser contract | Pinned `mcr.microsoft.com/playwright:v1.63.0-noble` image, host network, `npx playwright test` | 1 test passed: register, `/me`, HttpOnly cookie, CSRF rejection without token/Fetch Metadata, refresh, verification through real Mailpit link, protected shell, logout, then `/me` 401. The same test also passed with host Chrome. |
| Compose | `docker compose config --quiet`; `docker compose up -d --wait`; `docker compose ps` | Configuration valid; backend, frontend, PostgreSQL, Redis, and Mailpit healthy |
| Service connectivity | `curl` liveness/readiness on backend and through Vite; `pg_isready`; `redis-cli ping`; Mailpit `/api/v1/info` | Both health probes returned `{"data":{"status":"ok"}}`; PostgreSQL accepted connections; Redis returned `PONG`; Mailpit answered v1.27.1 |
| Guest API | `GET http://localhost:5174/api/v1/me` with JSON Accept | 401 |

The authentication feature tests individually passed before the complete suite. They cover normalized and duplicate registration, validation and response exposure, successful/failed/throttled login, session regeneration and logout, `/me`, password-link response parity, valid/invalid resets, signed/tampered verification, and resend throttling. Frontend tests cover store initialization and state transitions, route guards including a session that expires after initialization, HTTP error classification, and login validation display.

## Limits and follow-up

The pinned browser-image command passed locally; the GitHub Actions workflow has not executed on GitHub in this uncommitted repository. No production deployment, HTTPS proxy, real email provider, tenancy, roles, or backend ERP authorization rules are configured. The `/app` verification gate is a SPA navigation guard; future API resources must enforce their own verification and authorization rules. Password reset deliberately leaves the user signed out. Browser tests created local development users and Mailpit messages; they did not mutate foundation schema.

Phase 1.1 is complete against its local quality and browser acceptance checks. Phase 1.2 remains unimplemented.
