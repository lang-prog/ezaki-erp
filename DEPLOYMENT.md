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

## Local lifetime edition

Set `APP_EDITION=local` and configure the installation's public verification key in `LOCAL_LICENSE_PUBLIC_KEY`. The matching private signing key belongs only in the offline issuer's protected environment and must never be deployed with the application. Activation is verified locally; no heartbeat or online check is performed after activation. Back up the local database and the application's private storage as one installation unit.

## `php artisan serve`

For development, `php artisan serve` uses the Laravel public entrypoint and can be used alongside the Vite dev server. Run `npm run dev` in a second terminal. The API health route is `/api/v1/health`.