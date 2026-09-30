# Deployment

## XAMPP

1. Start MySQL in the XAMPP Control Panel. In phpMyAdmin, create a new, empty database named `ezaki_erp` with collation `utf8mb4_unicode_ci`.
2. In phpMyAdmin's **User accounts**, create `ezaki_erp_user` for host `localhost`, set a strong local-only password, and grant privileges only on `ezaki_erp.*`. Do not use MySQL `root` for the application. Keep the password out of `.env.example` and source control.
3. `.env.example` is the non-secret template. If `.env` does not exist, create it with `Copy-Item .env.example .env`. If `.env` already exists, **do not overwrite it**: back it up outside the web root, then have the operator change only the `DB_*` values to `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=ezaki_erp`, `DB_USERNAME=ezaki_erp_user`, and the local password. Preserve the existing `APP_KEY`; never paste it into `.env.example` or this document.
4. Enable Apache `mod_rewrite` and allow `AllowOverride All` for the public directory. The project-root `.htaccess` denies direct requests to source files; only the `public/` subdirectory is allowed under the default `htdocs` mapping.
5. For a clean URL, configure a vhost with the public folder as its document root, for example:

   ```apache
   <VirtualHost *:80>
	   ServerName ezaki-erp.test
	   DocumentRoot "D:/xampp/htdocs/ezaki-erp/public"
	   <Directory "D:/xampp/htdocs/ezaki-erp/public">
		   AllowOverride All
		   Require all granted
	   </Directory>
   </VirtualHost>
   ```

   Add `127.0.0.1 ezaki-erp.test` to the Windows hosts file and set `APP_URL=http://ezaki-erp.test`. Alternatively, add `Alias /erp "D:/xampp/htdocs/ezaki-erp/public"` and a matching `<Directory>` block with `AllowOverride All` and `Require all granted`; set `APP_URL=http://localhost/erp`. Never point `DocumentRoot` or an Alias at the repository root.
6. From the project root, run `composer install`, `php artisan migrate --force`, `npm install`, and `npm run build`. Back up an existing database before applying migrations. `migrate` is forward-only; do not use `migrate:fresh`, `migrate:refresh`, or drop/recreate commands against an existing database.
7. Test `/api/v1/health`, sign-in, static assets, and the browser UI through the configured vhost/Alias. Configure a mail transport before accepting real registrations. The local `log` mailer is development-only and writes verification links to the application log.
8. Run Laravel's scheduler every minute so the expiry reminder runs daily at 08:00 and deduplicates notices per owner/day. In Windows Task Scheduler, create a task triggered every minute that runs `D:\xampp\php\php.exe` with arguments `D:\xampp\htdocs\ezaki-erp\artisan schedule:run` and start-in `D:\xampp\htdocs\ezaki-erp`. On Linux hosts, use the standard `* * * * * php /path/to/project/artisan schedule:run` cron entry.

## Apache or cPanel

Set the site's document root to the project's `public` directory. If the host fixes the document root elsewhere, deploy the Laravel public entrypoint and its public assets there; do not expose `.env`, `vendor`, `storage`, or the project root over HTTP. Enable URL rewriting and grant Laravel write access to `storage` and `bootstrap/cache`.

Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` to the HTTPS site URL, a secure generated `APP_KEY`, a MySQL connection, secure session cookies, and a production mail transport. Then run migrations and `npm run build` as part of deployment. Use `php artisan config:cache`, `php artisan route:cache`, and `php artisan view:cache` after deployment configuration is final.

For production, set `SESSION_SECURE_COOKIE=true`, keep `SESSION_HTTP_ONLY=true` and `SESSION_SAME_SITE=lax` (use `none` only for a deliberately cross-site HTTPS client), and set `CORS_ALLOWED_ORIGINS` to a comma-separated, explicit HTTPS origin allowlist. Never use `*` with credentials. HSTS is emitted on HTTPS responses by default; if TLS terminates at a reverse proxy, forward the original HTTPS scheme and verify `TrustProxies` before enabling preload-style policies.

## Local lifetime edition

Set `APP_EDITION=local` and configure the installation's public verification key in `LOCAL_LICENSE_PUBLIC_KEY`. The matching private signing key belongs only in the offline issuer's protected environment and must never be deployed with the application. Activation is verified locally; no heartbeat or online check is performed after activation. Back up the local database and the application's private storage as one installation unit.

## `php artisan serve`
For development, `php artisan serve` uses the Laravel public entrypoint and can be used alongside the Vite dev server. Run `npm run dev` in a second terminal. The API health route is `/api/v1/health`.

## Release hardening runbook (v1)

### Release gates and local verification

Run from the repository root before exposing traffic:

```bash
php scripts/lint-openapi.php
php artisan release:preflight --production
php artisan test
vendor/bin/pint --test
BASE_URL=https://ezaki-erp.example ./scripts/smoke.sh
```

`release:preflight --production` fails closed for a non-production environment, debug mode, missing/non-rotated key, non-HTTPS `APP_URL`, insecure session cookies, ephemeral session/cache/queue drivers, development mailers, a non-MySQL production driver, unwritable runtime directories, an unreachable database, or pending migrations. The command intentionally omits passwords, keys, DSNs, and database exception text from its output. Run it after the final `.env` is loaded and after `config:cache` only if the cached values are the intended release values.

The smoke script always checks `/api/v1/health` and `/api/v1/version`. It checks login and `/api/v1/dashboard` only when an explicitly provisioned test account is supplied through `SMOKE_EMAIL` and `SMOKE_PASSWORD`; it never prints that password. A 401/403 from the optional authenticated probe is a test failure, not a reason to weaken authorization.

### Versioned API contract

The v1 contract is `docs/openapi/v1/openapi.json` (OpenAPI 3.1.0, base `/api/v1`). It covers health/version, session auth, dashboard, subscription, profile, accounting settings, branches, warehouses, and the local license activation boundary. Run `php scripts/lint-openapi.php` in CI and before a release. This is a dependency-free structural/route sanity check; a full external OpenAPI validator and live contract/drift test remain recommended in CI where a validator and a running application are available.

### XAMPP / Apache

1. Start MySQL and Apache in the XAMPP Control Panel. Create an empty `utf8mb4` database and a dedicated local application user with privileges only on that database. Never use MySQL `root` in `.env`.
2. Point the Apache vhost `DocumentRoot` only at `<repo>/public`, enable `mod_rewrite`, and grant `AllowOverride All` for that directory. Never map the repository root, `.env`, `vendor`, or `storage` as a document root.
3. Keep `.env` outside source control and outside any public directory. Set `APP_ENV=production`, `APP_DEBUG=false`, a unique `APP_KEY`, an HTTPS `APP_URL`, `SESSION_SECURE_COOKIE=true`, and the real MySQL/mail/queue/cache values. Do not copy secrets into `.env.example`.
4. Install/build and migrate forward only:

```text
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
```

Do not run `migrate:fresh`, `migrate:refresh`, or drop/recreate commands against an existing installation. Take a verified backup before migrations.

### Queue, cache, and session drivers

Production must use persistent, operator-managed services. The release gate rejects `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, and `SESSION_DRIVER=array`/`cookie` in production. The default `.env.example` uses database-backed queue/cache/session and therefore requires the corresponding Laravel tables and a worker. For Redis, configure the extension/service and use a persistent Redis namespace; do not put Redis passwords in command arguments or logs.

For database queue:

```text
php artisan queue:work database --sleep=3 --tries=3 --timeout=120 --backoff=10,30,90
php artisan queue:failed
php artisan queue:retry all
```

Run the worker under a process supervisor (Windows Task Scheduler/NSSM for XAMPP, or systemd/Supervisor on Linux), with the project directory as working directory. Restart workers after deployment so they load the new code. Do not delete failed jobs until the failure is understood. Use `php artisan queue:restart` during a graceful rollout.

Run Laravel's scheduler every minute. On XAMPP use Task Scheduler with `php.exe artisan schedule:run` and the project as start-in directory; on Linux use `* * * * * php /path/to/artisan schedule:run`. The scheduler must not be the only queue worker.

### Backup and restore procedure

Backups are created by `php artisan backup:database` or `scripts/backup.sh`. The command supports MySQL (`mysqldump --single-transaction ... | gzip`) and file-based SQLite development databases. It writes a per-artifact SHA-256 plus a JSON manifest (`ezaki-backup-v1`) containing driver, sizes, timestamps, and retention metadata; credentials are read from Laravel configuration through `MYSQL_PWD` and never appear in command-line arguments, manifests, or output. `--include-storage` adds a separate archive of `storage/app` so the local edition can preserve its database and private files as one installation set.

Examples:

```bash
php artisan backup:database --dry-run
php artisan backup:database --retention=14 --include-storage
php artisan backup:restore storage/app/backups/ezaki-YYYYmmdd-HHMMSS-mysql.json --dry-run
php artisan backup:restore storage/app/backups/ezaki-YYYYmmdd-HHMMSS-mysql.json --yes
```

The backup directory defaults to `storage/app/backups` and is ignored by Git. Retention deletes only old `ezaki-*` artifacts; `--retention=0` disables deletion. Operator-controlled `BACKUP_PRE_HOOK`, `BACKUP_POST_HOOK`, and `BACKUP_RETENTION_HOOK` may be used for local/offsite transfer or monitoring. Hooks are not a substitute for encryption, access control, or restore drills; they run with output suppressed and must never echo environment variables.

Restore is deliberately destructive and requires an interactive confirmation or explicit `--yes`. It validates the manifest, confines artifacts to its directory, and verifies SHA-256. SQLite receives a timestamped `.pre-restore-*` safety copy before replacement. MySQL restore streams the verified dump to `mysql` without putting the password in arguments. After restore, run `php artisan migrate:status`, `php artisan release:preflight --production`, and `scripts/smoke.sh`. Do not claim a MySQL restore succeeded until it has been run against a real MySQL instance; the sandbox release checks only syntax, contract structure, and SQLite-capable code paths.

Recommended policy for a single-site deployment: daily database plus private-storage backup, at least 14 retained local copies, and an encrypted/offsite copy with an operator-controlled hook. Target **RPO: 24 hours** for daily backups (or the documented schedule chosen by the operator) and target **RTO: 4 hours** for a tested local restore. These are targets, not proof: measure them during a restore drill and record actual dump, transfer, restore, migration, and smoke-test durations.

### Rollback

1. Put the site in maintenance mode or stop traffic and stop/restart queue workers so old jobs do not mutate the restored state.
2. Preserve logs and the current backup; never overwrite the only backup.
3. Roll back code to the last known-good commit/artifact, then restore the last verified database/storage manifest only when the failure requires data rollback. Code-only rollback is safer for additive migrations.
4. For irreversible/schema changes, use a forward fix or a tested down/compatibility migration; do not improvise `migrate:rollback` on production.
5. Run `migrate:status`, `release:preflight --production`, queue checks, and the smoke script. Confirm login, dashboard, a read-only core list, mail transport, and worker health before removing maintenance mode.
6. Record the incident, manifest checksum, commit, actual RPO/RTO, and any failed jobs.

### External verification still required

This repository-only pass cannot provide XAMPP/Apache, SMTP, Redis, a persistent queue worker, production HTTPS, load/P95, or a real MySQL migration/backup/restore proof. Run those integration checks in the deployment environment/CI (including foreign keys, `EXPLAIN`, queue retry/failure behavior, and a restore drill) before release acceptance.

