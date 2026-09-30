# Phase 1.0 validation record

Validated locally on 2026-09-30. This records observed local results, not a GitHub-hosted CI run or a production deployment.

## Initial inspection

The repository contained only `.git`, on the unborn `feature/foundation` branch, with no commits or working files. No parent `AGENTS.md` applied. Existing unrelated containers were left untouched; their occupied ports informed the choice of 8088/5174.

| Tool | Host | Validated container |
| --- | --- | --- |
| Docker | 29.1.2 | Host engine |
| Docker Compose | 5.0.0 | Host plugin |
| PHP | 8.5.4, without PostgreSQL/Redis extensions | 8.5.11, required extensions present |
| Composer | 2.9.5 | 2.10.3 |
| Node | 22.23.2 | 24.21.0 |
| npm | 10.9.8 | 11.19.0 |
| PostgreSQL | Not used from host | 18.6 |
| Redis | Not used from host | 8.10.2; PHP extension 6.3.0 |

Laravel resolved to 13.34.0 and Sanctum to 4.3.3. Both application processes run as UID/GID 1000. See the repository package lockfiles for exact library versions.

## Results

| Check | Observed result |
| --- | --- |
| Compose configuration | Valid |
| Both Docker images | Built successfully |
| Locked Composer install | Passed inside PHP 8.5 container |
| Composer manifest/platform checks | Passed, including PostgreSQL and Redis extensions |
| Laravel boot | `artisan about` passed |
| Routes | Only `GET/HEAD api/v1/health` and `GET/HEAD api/v1/ready` |
| PostgreSQL migration | Standard framework identity/session migration applied; status reports Ran |
| Backend tests | 7 passed, 24 assertions, including real PostgreSQL/Redis integration |
| Pint | Passed, 29 PHP files |
| Larastan | Level 8 passed, no errors, no baseline |
| Locked npm install | `npm ci` passed under Node 24 |
| ESLint | Passed with zero warnings |
| Prettier | Passed |
| TypeScript | `vue-tsc --noEmit` passed |
| Vitest | 3 tests passed |
| Production assets | Vite build passed; approximately 143.56 kB JS / 9.74 kB CSS before gzip |
| Direct HTTP probes | Liveness/readiness returned 200 with `{"data":{"status":"ok"}}` |
| Vite proxy | `/api/v1/ready` through port 5174 returned the same healthy JSON |
| Frontend HTTP serving | Port 5174 served the SPA entry HTML |
| Container health | All four services healthy |
| Dependency audits | Composer and npm reported no known vulnerabilities |

## Commands executed

Inspection included `pwd`, `ls -la`, `rg --files`, `git status`, `git branch --show-current`, `git log -1 --oneline`, `docker --version`, `docker compose version`, `docker info`, `docker ps`, `php --version`, `php -m`, `composer --version`, `node --version`, `npm --version`, and `df -h .`. The initial Git log command correctly reported no commits.

Scaffolding and package resolution:

```sh
composer create-project laravel/laravel backend '^13.0' --no-install --no-scripts --no-interaction
# From backend, for initial lockfile resolution on the host only:
composer update --ignore-platform-req=ext-pdo_pgsql --ignore-platform-req=ext-redis --no-interaction --prefer-dist
php artisan key:generate --no-interaction
composer format
composer analyse
composer test -- --exclude-group=integration
composer validate --strict
```

Frontend package resolution ran in `node:24-bookworm-slim`, with the frontend mounted at `/app`, working directory `/app`, UID/GID 1000, and `npm_config_cache=/tmp/npm-cache`:

```sh
npm install vue@^3 vue-router@^4 pinia@^3 axios@^1
npm install -D vite@^7 @vitejs/plugin-vue@^6 typescript@~5.9 vue-tsc@^3 @types/node@^24 tailwindcss@^4 @tailwindcss/vite@^4 vitest@^4 @vue/test-utils@^2 jsdom@^27 eslint@^9 @eslint/js@^9 typescript-eslint@^8 eslint-plugin-vue@^10 eslint-config-prettier@^10 prettier@^3
npm install -D eslint@^10 @eslint/js@^10
```

Final container setup and validation commands (from repository root; builds/checks were also run individually while iterating):

```sh
cp .env.example .env
cp backend/.env.example backend/.env
docker pull node:24-bookworm-slim
docker compose pull postgres redis
docker compose config --quiet
docker compose build backend
docker compose build frontend
docker compose up -d --wait postgres redis
docker compose run --rm --no-deps backend composer install --no-interaction --prefer-dist
docker compose run --rm --no-deps backend composer check-platform-reqs
docker compose run --rm --no-deps frontend npm install-scripts approve esbuild
docker compose run --rm --no-deps frontend npm ci
docker compose run --rm --no-deps frontend npm run format
docker compose run --rm --no-deps frontend npm run quality
docker compose run --rm backend php artisan migrate --no-interaction
docker compose up -d --wait
docker compose exec -T backend composer quality
docker compose exec -T backend composer validate --strict
docker compose exec -T backend php artisan about
docker compose exec -T backend php artisan route:list
docker compose exec -T backend php artisan migrate:status
docker compose exec -T backend composer audit
docker compose exec -T backend composer show --direct
docker compose exec -T frontend npm run quality
docker compose exec -T frontend npm audit
docker compose exec -T frontend npm ls --depth=0
docker compose exec -T frontend node --version
docker compose exec -T frontend npm --version
docker compose exec -T postgres postgres --version
docker compose exec -T redis redis-server --version
docker compose exec -T backend id
docker compose exec -T frontend id
curl --fail --silent --show-error http://localhost:8088/api/v1/health
curl --fail --silent --show-error http://localhost:8088/api/v1/ready
curl --fail --silent --show-error http://localhost:5174/api/v1/ready
curl --fail --silent --show-error http://localhost:5174/
docker compose ps
git diff --check
git diff --stat
git ls-files --others --exclude-standard
```

`composer quality` executes Pest, Pint check mode, and PHPStan. `npm run quality` executes ESLint, Prettier check mode, vue-tsc, Vitest, and the production build (which also checks types).

## Resolved setup issues and limits

- Initial host dependency resolution temporarily ignored the two missing host PHP extensions. Final installation and `check-platform-reqs` succeeded inside the actual container without ignores; host PHP is not the supported database runtime.
- An initial Larastan run rejected a generic annotation on Laravel's non-generic `JsonResource`. Replaced it with a concrete resource property type; the final run passes without suppressions.
- An early test run happened before key generation and failed with `MissingAppKeyException`. Generated the ignored local application key as documented; all tests then passed. One early Composer command was accidentally run at repository root, where no Composer project exists; reran it successfully from `backend`.
- The first PHP image build attempted to recompile DOM and failed because the standalone compile lacked Lexbor headers. The official base image already includes DOM/XML/mbstring; the final Dockerfile compiles only missing extensions and builds successfully.
- npm warned that ESLint 9 was unsupported. Updated to compatible ESLint 10. Explicitly approved the locked esbuild install script; final `npm ci` succeeds without that warning.
- Explicit Tailwind source scanning is limited to `src` so prior build artifacts do not affect generated CSS when the frontend is mounted separately from the root `.gitignore`.
- No browser was connected to the computer-use tool (Chrome unavailable; browser inventory empty). Visual screenshot and real-browser interaction checks could not be performed. Component tests, HTML serving, asset build, and real proxy connectivity were verified.
- GitHub-hosted CI has not run: no commit or push was authorized. Its substantive Compose/quality commands were executed locally.
- Containers remain running for review. Development credentials and development servers are not production deployment configuration.

## Git state

No commits, pushes, branch changes, or staging operations were performed. Because the repository began without tracked files, `git diff --stat` returned **zero bytes of output**. All deliverable files are new and untracked. Ignored local environments, dependencies, caches, logs, and build output are not deliverables.

The complete grouped source-file inventory follows.

### Root configuration and guidance

```text
.dockerignore
.editorconfig
.env.example
.gitignore
.nvmrc
AGENTS.md
README.md
```

### CI

```text
.github/workflows/quality.yml
```

### Docker

```text
docker-compose.yml
docker/backend/Dockerfile
docker/frontend/Dockerfile
```

### Backend

```text
backend/.editorconfig
backend/.env.example
backend/.gitattributes
backend/.gitignore
backend/app/Http/Controllers/Controller.php
backend/app/Http/Controllers/HealthController.php
backend/app/Http/Resources/HealthResource.php
backend/app/Models/User.php
backend/app/Providers/AppServiceProvider.php
backend/app/Services/ReadinessCheck.php
backend/artisan
backend/bootstrap/app.php
backend/bootstrap/cache/.gitignore
backend/bootstrap/providers.php
backend/composer.json
backend/composer.lock
backend/config/app.php
backend/config/auth.php
backend/config/cache.php
backend/config/database.php
backend/config/filesystems.php
backend/config/logging.php
backend/config/mail.php
backend/config/queue.php
backend/config/sanctum.php
backend/config/services.php
backend/config/session.php
backend/database/.gitignore
backend/database/factories/UserFactory.php
backend/database/migrations/0001_01_01_000000_create_users_table.php
backend/database/seeders/DatabaseSeeder.php
backend/phpstan.neon
backend/phpunit.xml
backend/pint.json
backend/public/.htaccess
backend/public/index.php
backend/public/robots.txt
backend/routes/api.php
backend/routes/console.php
backend/storage/app/.gitignore
backend/storage/app/private/.gitignore
backend/storage/app/public/.gitignore
backend/storage/framework/.gitignore
backend/storage/framework/cache/.gitignore
backend/storage/framework/cache/data/.gitignore
backend/storage/framework/sessions/.gitignore
backend/storage/framework/testing/.gitignore
backend/storage/framework/views/.gitignore
backend/storage/logs/.gitignore
backend/tests/Feature/HealthTest.php
backend/tests/Integration/ReadinessTest.php
backend/tests/Pest.php
backend/tests/TestCase.php
```

### Frontend

```text
frontend/.npmrc
frontend/.prettierignore
frontend/.prettierrc.json
frontend/eslint.config.js
frontend/index.html
frontend/package-lock.json
frontend/package.json
frontend/src/App.vue
frontend/src/__tests__/App.test.ts
frontend/src/lib/api.ts
frontend/src/main.ts
frontend/src/router/index.ts
frontend/src/style.css
frontend/src/views/FoundationView.vue
frontend/src/views/NotFoundView.vue
frontend/tsconfig.json
frontend/vite.config.ts
```

### Documentation

```text
docs/architecture/system-overview.md
docs/decisions/0001-foundation.md
docs/domain/README.md
docs/phases/phase-01-core-platform.md
docs/phases/phase-01-foundation-validation.md
```
