# ADR 0002: First-party SPA authentication

- Status: Accepted for Phase 1.1
- Date: 2026-09-30

## Context

CoreERP has a Vue SPA on `http://localhost:5174` and a Laravel API on `http://localhost:8088`. Vite already proxies `/api` over the internal Compose network. Phase 1.1 needs browser login, account recovery, and email verification without implementing tenant or ERP access rules.

## Decision

Use Laravel's `web` guard and server-side Redis sessions. Sanctum's stateful API middleware lets `auth:sanctum` read that session on `/api/v1/me`. Fortify owns the conventional unversioned registration, login, logout, reset, and verification routes and Laravel's session lifecycle. The browser first calls `/sanctum/csrf-cookie`, then makes an Axios credentialed request with the `X-XSRF-TOKEN` header. The browser stores the HttpOnly session cookie; the Vue auth store holds only the current user's public representation and initialization state.

JWT would require token issuance, browser storage and rotation rules for a first-party SPA. Session cookies fit Laravel's established CSRF and logout behavior and avoid JavaScript-readable bearer credentials. Sanctum's token table and token-issuing routes are not installed in this milestone.

Fortify views are disabled. Only registration, password reset, and email verification features are enabled. Account creation normalizes email and uses a shared password rule; it creates only a user. Reset links use Laravel's password broker and point to the SPA. Signed verification links use Laravel's canonical public API URL and open the backend directly; after verification, the browser returns to the SPA. Password reset does not automatically log the user in. `/api/v1/me` is accessible before email verification so the SPA can show the verification state; `/app` is gated in the SPA until verified. Guarded navigation rechecks `/api/v1/me`, including after initial load, so an expired server session cannot rely on a cached client user. Future sensitive API routes must enforce authorization and verification server-side.

## Origin and cookie configuration

Local browser traffic is same-origin through Vite. The proxy forwards `/api`, `/sanctum`, and Fortify mutation paths. Browser GETs for `/login`, `/register`, and password pages remain SPA routes. Vite's internal backend host is not a valid external email-link host, so Laravel forces URL generation to `APP_URL=http://localhost:8088` for signed verification links. The app and API use the `localhost` hostname with different ports, which share a host-only cookie. Using `127.0.0.1` for one application and `localhost` for the other would break that cookie relationship.

`SANCTUM_STATEFUL_DOMAINS` includes the local SPA ports. CORS allows only the configured SPA origin, known methods and headers, and credentials. The local proxy means browsers normally do not make cross-origin requests, but the exact-origin CORS configuration supports a later `app.example.com` / `api.example.com` deployment. In that deployment, set `VITE_API_ORIGIN=https://api.example.com`, `APP_URL=https://api.example.com`, `FRONTEND_URL=https://app.example.com`, `SANCTUM_STATEFUL_DOMAINS=app.example.com`, `CORS_ALLOWED_ORIGINS=https://app.example.com`, `SESSION_DOMAIN=.example.com`, and `SESSION_SECURE_COOKIE=true`. HTTPS, trusted proxy configuration, and real mail delivery remain deployment responsibilities. The cookie stays HttpOnly with SameSite=Lax. The `XSRF-TOKEN` cookie is intentionally JavaScript-readable for Axios; it is a CSRF token, not an authentication credential.

Redis was already present and is suitable for shared session state and rate limits across future application instances. Sessions are encrypted before storage. The existing database `sessions` table is left intact from Laravel's scaffold but is unused. No new schema is required: the users and password-reset-token tables already existed. Local mail goes to Mailpit over the internal Compose network; its web UI is loopback-only on port 8026.

## Security boundaries

Fortify regenerates the session ID on registration and login, and invalidates the session plus CSRF token on logout. Laravel's normal CSRF middleware protects mutation routes; Laravel 13 accepts same-origin Fetch Metadata or a matching token. The real-browser test checks both the browser flow and a tokenless request without Fetch Metadata. Login has per-email/IP and broader per-IP limits; verification links/resends use Fortify's throttle. Laravel's password broker stores reset-token hashes, expires tokens after 60 minutes, and throttles repeated requests. Forgot-password responses are identical for registered and unregistered addresses. Registration duplicate-email validation still reveals that an address is taken, as expected for account creation.

The user resource exposes only ID, name, email, and verification status. The model's mass-assignment list stays limited to name, email, and password. Passwords are validated centrally and hashed by Laravel's model cast. No authentication secret is put in localStorage, sessionStorage, a persistent Pinia store, or a custom cookie. Browser and backend tests cover the relevant boundaries; this decision does not claim tenant isolation, production deployment hardening, or universal protection against account enumeration through timing.

## References

- [Laravel Sanctum SPA authentication](https://laravel.com/docs/13.x/sanctum#spa-authentication)
- [Laravel Fortify](https://laravel.com/docs/13.x/fortify)
- [Laravel email verification](https://laravel.com/docs/13.x/verification)
