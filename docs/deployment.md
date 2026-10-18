# Deploying Dot.Billing

Production is `https://billing.infodot.co.za`, on the shared cPanel account that also runs the other Dot apps. Everything goes through GitHub Actions, by hand: nothing deploys on a push.

> **Status.** These workflows were written and checked without access to the host. Nothing here has run against it. They stay inert until the checklist below is done: with no repository secrets the first SSH step fails and nothing is touched. See "What was and was not verified" at the end.

| Workflow | Runs | What it does |
|---|---|---|
| `ci.yml` | every PR and push; called by `deploy.yml` | PHPUnit on PHP 8.4 with in-memory SQLite. The release gate. (Added by the open "CI: run tests on every pull request" PR #3; `deploy.yml` calls it with `uses: ./.github/workflows/ci.yml` and fails without it.) |
| `drift.yml` | manual, **read-only** | Compares every tracked file of `main` with the host's copy (sha256) and prints counts and paths. Run it before the takeover. |
| `deploy.yml` | manual, `confirm=adopt` once, then `confirm=deploy` | See below. |

Merge order: the CI PR (#3) first, then the deploy-pipeline PR. If the deploy PR is merged into a base that does not have `ci.yml`, the run fails at the gate.

## Prerequisites (do these once; none is done by the pipeline)

1. **DNS.** An A or CNAME record for `billing.infodot.co.za` pointing at the cPanel host, and a valid TLS certificate (AutoSSL) so `https://billing.infodot.co.za` answers. The smoke check and `APP_URL` assume https.
2. **cPanel subdomain and docroot.** Create the subdomain `billing.infodot.co.za` with document root `~/billing.infodot.co.za`. Select PHP 8.4 for it (the code requires `^8.3`, CI runs 8.4) with the extensions Laravel and the database driver typically need (`pdo_pgsql` or `pdo_sqlite`, `mbstring`, `intl`, `bcmath`, `curl`, `xml`, `zip`). The host needs `git`, `composer`, `rsync` and `flock`; it has no Node, which is why the build happens on the runner.
3. **Folder layout** (the layout already live for Dot.Mines and Dot.Dopemine and used by Dot.Central's bootstrap): the docroot is `~/billing.infodot.co.za`, the repository lives at `~/billing.infodot.co.za/billing` (that path is `DEPLOY_PATH`), and the docroot holds a front controller shim, a copy of `public/.htaccess` and symlinks into the app's `public/`:
   ```
   cd ~/billing.infodot.co.za
   cat > index.php <<'PHP'
   <?php require __DIR__.'/billing/public/index.php';
   PHP
   for t in build images favicon-16x16.png favicon-32x32.png apple-touch-icon.png favicon.ico robots.txt; do ln -sfn "billing/public/$t" "$t"; done
   ```
   and, after the first adopt, `cp billing/public/.htaccess .htaccess`. This is the Dot.Central convention; I could not inspect the host, so confirm it against how `central.infodot.co.za` is wired. The pipeline itself only guarantees `~/billing.infodot.co.za/build` (a symlink to `billing/public/build`, or a mirrored copy if a real directory is already there). Remove any "coming soon" `index.html` from the docroot, or it will win over `index.php`.
4. **An empty, prepared app folder** (first install only): create `~/billing.infodot.co.za/billing/` containing the `.env` (next item) and an empty `public/` directory. The adopt run turns it into a checkout of `main` without touching the `.env`, `storage/` or `database/`. The pipeline refuses to run without a `.env` and does not create one.
5. **Database.** The app's `.env.example` points at PostgreSQL (`DB_CONNECTION=pgsql`, database `infodot`). Give this platform its **own** database and user (cPanel PostgreSQL or MySQL Databases; a MySQL host needs `DB_CONNECTION=mysql`). Dot.Central chose SQLite for itself on 2026-10-16; that is also workable here (`DB_CONNECTION=sqlite`, `DB_DATABASE=/home/<user>/billing.infodot.co.za/billing/database/database.sqlite`, create the empty file). **Decide this before the first `migrate`.** The rollback tarball covers a SQLite file but not PostgreSQL or MySQL: take a cPanel backup (or `pg_dump`) first.
6. **The host `.env`** (mode 600, owner-written; the pipeline only forces `APP_DEBUG=false` and `APP_ENV=production` in it):

   | Key | Value |
   |---|---|
   | `APP_NAME`, `APP_URL` | `APP_URL=https://billing.infodot.co.za`. `.env.example` has a stale or `http://` address; the pipeline warns about `http://`. |
   | `APP_KEY` | `base64:` plus 32 random bytes, generated once (there is no `artisan` on the host before the first adopt, so use `php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'`). Keep a copy. |
   | `DB_*` | From item 5. |
   | `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` for all three (the tables ship with the default migrations). |
   | `MAIL_*` | Real SMTP. `.env.example` ships `MAIL_MAILER=log`, which sends nothing; sign-up verification and password resets need working mail. Use the same kind of SMTP account Dot.Doc uses (`no-reply@infodot.co.za`): `MAIL_MAILER=smtp`, host, port, username, password, `MAIL_FROM_ADDRESS`. |
   | `BROADCAST_CONNECTION` | `log`. See "Reverb" below. |
   | `LOG_CHANNEL`, `LOG_LEVEL` | `daily` and `info` are sensible. |
   | `INFODOT_REDEEM_URL`, `INFODOT_PLATFORM_KEY=billing`, `INFODOT_SERVICE_SECRET`, `INFODOT_REDEEM_TIMEOUT`, `INFODOT_PROVISION_USERS` | Sign-in through InfoDot. Present in `.env.example` once the open sign-in PR (#2) is merged; see item 8. |
   | `BILLING_*` (`BILLING_INVOICE_NUMBER_FORMAT`, `BILLING_PAYMENT_TERMS_DAYS`, `BILLING_TAX_RATE`, `BILLING_ISSUER_*`, `BILLING_FREE_*`, `BILLING_AUTO_FINALIZE_INVOICES`) | Arrive with the open billing stack (#4). Defaults work; set the issuer name, address, tax number and email before the first real invoice. |
   | `BILLING_ENTITLEMENT_SECRET_FILES`, `BILLING_ENTITLEMENT_SECRET_MINES` | Arrive with the stack (#5). One secret per calling platform, blank means that platform cannot call the entitlements API. Generate with `php -r 'echo bin2hex(random_bytes(32));'` and put the same value in the caller. |
   | `NOTIFY_INGEST_URL`, `NOTIFY_INGEST_SECRET` | Arrive with the stack (#6). Overdue reminders go to Dot.Notify; blank disables them. The secret must equal `NOTIFY_INGEST_SECRET_BILLING` in Dot.Notify's `.env`. |

   The open billing stack (#4, #5, #6) turns the scheduler line into real work (invoice and subscription commands) and adds a queued job; read its PRs before the first `migrate` (money moves to integer cents, foreign keys become restrictive). The pipeline already copes with the queue (see below).
7. **Repository secrets** (Settings, Secrets and variables, Actions, on `sakhilebhayi/Dot.Billing`; never printed by the workflows):

   | Secret | Value |
   |---|---|
   | `DEPLOY_HOST` | `billing.infodot.co.za` (used as the SSH host and as the docroot folder name `~/<DEPLOY_HOST>`). It must resolve to the cPanel server. |
   | `DEPLOY_USER` | The cPanel account (the other Dot apps use `infodotc`). |
   | `DEPLOY_SSH_KEY` | A private key whose public half is authorised for that account. A dedicated deploy key is better than a personal one. |
   | `DEPLOY_KNOWN_HOSTS` | The output of `ssh-keyscan -H billing.infodot.co.za` (`StrictHostKeyChecking=yes` makes a wrong or changed host fail closed). |
   | `DEPLOY_PATH` | Optional. Set it to `/home/<user>/billing.infodot.co.za/billing` for the first install; left unset, the app is the one `artisan` found within two levels of `~/billing.infodot.co.za`, which only works once the code is already there. |
8. **InfoDot sign-in.** The platform signs people in through InfoDot's redeem endpoint, which needs three things on the InfoDot side. As read from InfoDot `origin/main` on 2026-10-18: (a) `ECOSYSTEM_SECRET_BILLING` in InfoDot's `.env`, equal to this app's `INFODOT_SERVICE_SECRET` (generate with `php -r 'echo bin2hex(random_bytes(32));'`); (b) `'billing'` is **not yet** in the `service_secrets` list in InfoDot's `config/ecosystem.php`, so the redeem call refuses this platform until that list is extended (an InfoDot code change); (c) the registry entry `'billing' => [... 'active' => false]` must be flipped to `true` ("marking the platform live") once the site really answers, so the platform switcher and error pages list it. `ECOSYSTEM_TRUSTED_DOMAINS` already covers `infodot.co.za` subdomains.
9. **Other ecosystem wiring.** Dot.Notify: with the stack, overdue reminders are queued to Notify. Leave `NOTIFY_INGEST_URL` blank until Notify is live. No `ANALYTICS_*` variables exist in this app (only the `DOT_ANALYTICS_URL` link in the shared registry), so Dot.Analytics needs nothing here.

## First deploy, in order

1. Merge the CI PR (#3), then this PR. Set the repository secrets (item 7).
2. Run **Drift Report** (`gh workflow run drift.yml`; on a fresh folder `DEPLOY_PATH` must be set, because there is no `artisan` to discover yet). It is read-only. On a brand-new empty folder it should report every file as absent; that is the expected answer.
3. Run **Deploy to Production** with `confirm=adopt` (`gh workflow run deploy.yml -f confirm=adopt`). The CI gate runs first, then: rollback copy, takeover into a checkout of `main` (a fresh folder prints its whole file list under "Tracked files that differ", which is noise, not a problem), `composer install --no-dev`, `APP_DEBUG=false` and `APP_ENV=production` forced, the staged frontend swapped in, the docroot `build/` link, the **list** of pending migrations (it does **not** run them), cache rebuild, `artisan up`, cron lines, smoke check.
4. Read the pending-migration list. Back up the database. Then run `confirm=deploy` (it migrates). On a brand-new empty database you may instead pass `-f confirm=adopt -f migrate=migrate` in step 3 and skip this one.
5. Finish the docroot wiring from item 3 if it was not done (`.htaccess` copy), re-run the smoke by running `confirm=deploy` again, then check the site by hand.
6. When the site answers and sign-in works, flip the registry flag in InfoDot (item 8c).

No seeder is run by any step; the first account arrives through InfoDot sign-in or registration.

## Every release after that

`gh workflow run deploy.yml -f confirm=deploy`. The gate runs first; a red gate stops everything before the host is touched. A second run waits for the first (one concurrency group per platform, a running deploy is never cancelled; GitHub keeps at most one waiting run).

## What the cron and queue step installs

The account has **one shared crontab** for every Dot app. A concurrent write once wiped it, and a read that transiently answered empty once led a pipeline to write an empty crontab that killed Dot.Mines' scheduler for six weeks. So the step takes the shared lock (`flock` on `~/.dot-crontab.lock`, 120 s), reads the crontab with a retry (an empty answer is trusted only when the host says "no crontab for"; five empty or failed reads abort without writing), removes only lines carrying this app's marker `# dot-billing`, appends its own, writes, reads back and verifies that the other lines are unchanged and its own count is as expected, and restores the previous crontab if not.

The lines are chosen from the code that was just deployed, not hard-coded:

- `schedule:run` is installed when `artisan schedule:list` shows any task.
- `queue:work --stop-when-empty --tries=3 --timeout=90 --max-time=50` is installed when `app/` contains `ShouldQueue`, `->queue(` or `Queue::push/later`, and the host `.env` does not say `QUEUE_CONNECTION=sync`. It is cron-driven, not a daemon (shared cPanel cannot keep one running): each minute's worker drains the queue and exits, and never lives past about 50 seconds, so new code is picked up within a minute.
- If neither is needed the crontab is not written. If a later release removes the last task, the next deploy removes this app's lines.

For this release:

- `* * * * *  artisan schedule:run`  (marker `# dot-billing`). `routes/console.php` schedules `billing:scan-alerts` every fifteen minutes (`withoutOverlapping()->onOneServer()`).
- **No queue worker line on main**: nothing in `app/` is queued yet.

The open billing stack (#4 to #6) adds `billing:advance-subscriptions` (daily 01:00) and `billing:generate-invoices` (daily 01:30), both covered by the same `schedule:run` line, and `SendNotifyEvent implements ShouldQueue`. As soon as that code is on main the next deploy detects it and installs the second line, `queue:work --stop-when-empty --tries=3 --timeout=90 --max-time=50`, with no edit to the workflow. The job brings its own `$tries = 6` and backoff, which override `--tries=3`. `onOneServer()` needs the cache store to support locks; `CACHE_STORE=database` does (the `cache_locks` table ships with the default migration).

Each line is `* * * * * cd <app> && <absolute php> artisan ... > storage/logs/cron-*.log 2>&1 # dot-billing`, so the last output of each is in `storage/logs/cron-scheduler.log` and `storage/logs/cron-queue.log`. Every deploy log ends this step with `== crontab: N line(s) for # dot-billing installed, M other line(s) preserved`; **M must never shrink** between runs unless someone removed a line by hand.

## Reverb

`laravel/reverb` is a dependency and `.env.example` says `BROADCAST_CONNECTION=reverb`, but nothing in `app/`, `routes/` or `resources/js` broadcasts an event or subscribes with Echo. Reverb is a long-lived websocket server and shared cPanel cannot run one, so **the pipeline never starts, installs or supervises it**. Set `BROADCAST_CONNECTION=log` in the host `.env`. If a future feature needs real broadcasting, it needs a host that can run a daemon (or a hosted provider such as Pusher, which is a code and configuration change, not a pipeline change).

## A red run leaves the site down, on purpose

A failure **after** maintenance mode starts (code swap, `composer install`, migrations, caches) leaves the site in maintenance mode and prints the rollback copy's name. This is deliberate: an automatic rollback in the Dot.Agents pipeline fired after failures that never touched the server. A failure before maintenance mode (the gate, the build, SSH, the upload, a missing `.env`) leaves the site serving the previous release, and the log says so. A failure in the cron or smoke steps happens after `artisan up`, so the site is serving, but the run is red and the release is not confirmed healthy.

To recover: read the failing step's log, fix the cause, and **run the workflow again**; a successful run ends with `artisan up`. If you would rather go back, restore the rollback copy (below) and run `php artisan up`.

## Rollback

Before maintenance mode, every run writes `~/dot-billing-<mode>-<UTC time>.tgz` (mode 600, outside every web root): the app folder without `vendor/`, `node_modules/`, `storage/logs` and the build. The newest three are kept. To restore: stop writes (maintenance mode is already on after a failure), `tar xzf ~/dot-billing-<...>.tgz -C ~/billing.infodot.co.za/billing`, `composer install --no-dev`, rebuild the caches (`php artisan config:cache`, `route:cache`, `view:cache`, `event:cache`), then `php artisan up`. Code only: a migration that already ran is not undone, and a PostgreSQL or MySQL database is **not** in the tarball. A SQLite file is. Host-only migration files found at deploy time are moved to `~/dot-billing-quarantine-<time>/`, never deleted.

## Reading the log

- **Actions run page.** Steps in order: Check the inputs, CI gate, Build the frontend, Set up SSH, Locate the app, Upload the built frontend, Deploy the code, Install the cron lines, Smoke-check. Host output lines start with `== `. `::error::` lines say what failed and whether the site is down.
- **On the host:** `storage/logs/laravel.log` (or the dated `laravel-*.log` when `LOG_CHANNEL=daily`), `storage/logs/cron-scheduler.log`, `storage/logs/cron-queue.log`, `crontab -l | grep '# dot-billing'`. This repository has no read-only log-tail workflow (InfoDot and Dot.Agents have `server-logs.yml`); adding one is a natural follow-up.
- **Smoke check lines:** `200 https://billing.infodot.co.za/`, `/login`, `/up`, the built CSS and JS under `/build/`, and `No debug page on POST /up (N bytes)`. A 404 on `/build/...` means the docroot is not serving the new build (see item 3). A debug page means `APP_DEBUG` is still on.

## Known limits

- The pipeline updates an install the owner has prepared; it does not create the `.env`, the database, the subdomain, the docroot shim or the DNS. There is no `bootstrap.yml` here (Dot.Central has one); the manual steps above stand in for it.
- The first `schedule:run` or queue worker finds whatever is already due or queued. On a brand-new database that is nothing.
- No Dot.Brain guardian health document, no scheduler heartbeat probe (so no `scheduler-watchdog.yml`), no database backups beyond the rollback tarball. Those need app changes and are not part of this pipeline.
- A shared cPanel host cannot run Reverb or any daemon (see above).
- `crontab -l` can transiently answer empty on this host; the step retries and refuses to write rather than guess. A red cron step is safe to re-run.
- The repository root carries a tracked static `index.html` ("dot.projects - Coming Soon", from the scaffold). If a docroot ever points at the app folder itself rather than at `public/`, that file answers `/` with 200 and the smoke check passes on the placeholder; the `/login` check would then fail. Keep the docroot wiring below.
- No payment provider is configured or needed; payments are recorded manually.

## What was and was not verified

Verified without a host: both workflows parse as YAML and pass `actionlint` 1.7.12 (with its embedded `shellcheck`); every embedded script block passes `bash -n`; `npm ci && npm run build` produces `public/build/manifest.json` (an entry CSS and JS); the app answers `/` 200, `/login` 200, `/up` 200 and `POST /up` 405 (1011 bytes, no debug page) when served locally with `APP_DEBUG=false`; and the host and cron scripts were run end to end against a **fake host on a developer machine** (temporary HOME, local git remote, SQLite, a fake `crontab` and `flock`): adopt of an empty prepared folder, a following deploy with migrations, a failure after maintenance mode (left down, then recovered by a re-run), and the cron step with foreign lines present, with transient empty reads, with permanently empty reads (refused, crontab untouched), with no crontab at all, and idempotently on a re-run.

Not verified (needs the real host and secrets): SSH, `rsync` and `flock` availability, the host's PHP version and extensions, the docroot wiring and `.htaccess` behaviour, `composer install` on the host, real database migrations, cPanel's `crontab`, the smoke checks against the real domain, and InfoDot sign-in.
