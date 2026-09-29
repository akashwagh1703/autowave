# Deployment

- **Last updated:** 2026-09-30 (validated on the production server, AW-004)
- **Script:** [`scripts/deploy.sh`](../../scripts/deploy.sh) · Runbooks: [deployment](../11-runbooks/deployment.md),
  [rollback](../11-runbooks/rollback.md) · Server: [production.md](production.md)

## Strategy

Atomic release directories with a `current` symlink. Each deploy clones a fresh copy of the branch into
`releases/<timestamp>`, builds it, migrates, checks it, and only then renames `current` to point at it. A
failed build never touches the live site.

```text
/var/www/autowave-platform/
├── current -> releases/20260929180445   # live release (Nginx root is current/public)
├── releases/<timestamp>/                 # last 5 kept
├── shared/.env                           # production secrets (600, owner autowave)
├── shared/storage/                       # logs, framework cache, uploads (app/public)
├── deploy.sh                             # copy of scripts/deploy.sh, refreshed by every deploy
└── deployments.log                       # one line per deploy / rollback
```

## Usage

On the server, as root or any sudoer:

```bash
sudo -iu autowave /var/www/autowave-platform/deploy.sh                 # deploy master
sudo -iu autowave /var/www/autowave-platform/deploy.sh deploy v1.0     # deploy another branch
sudo -iu autowave /var/www/autowave-platform/deploy.sh releases        # list releases, mark the live one
sudo -iu autowave /var/www/autowave-platform/deploy.sh rollback        # back to the previous release
```

The code must be pushed to GitHub first; the server clones `git@github.com:akashwagh1703/autowave.git`
with a read-only deploy key (`/home/autowave/.ssh/id_ed25519`).

## What `deploy` does

1. Takes a lock (`.deploy.lock`), so two deploys cannot overlap.
2. `git clone --depth 1 --branch <branch>` into `releases/<timestamp>`; writes `REVISION` (short SHA, branch).
3. Links `shared/.env` and `shared/storage` into the release.
4. `composer install --no-dev --optimize-autoloader`.
5. `npm ci && npm run build` (Node heap capped at 1536 MB for the 2 GB server), then deletes `node_modules`.
6. `php artisan migrate --force` — against the live database, **before** the switch (see rules below).
7. `storage:link`, `optimize` (config, routes, views, events), `autowave:health` (database, Redis, cache).
8. Switches `current` atomically, then `queue:restart` (the systemd worker restarts on the new code) and
   reloads PHP-FPM if the deploy user may (`sudo -n`; optional — `$realpath_root` already makes new
   requests use the new path).
9. Records the deploy, keeps the last 5 releases, smoke-tests `https://<app host>/up` through 127.0.0.1,
   and refreshes `/var/www/autowave-platform/deploy.sh` from the release.

If any step before the switch fails, the unfinished release is deleted and the live site is unchanged. If
migrations already ran, the script says so: they must work with the live release (rule 1).

Settings can be overridden with environment variables: `APP_DIR`, `REPO`, `BRANCH`, `KEEP_RELEASES`,
`PHP_BIN`, `DEPLOY_USER`, `NODE_MEMORY_MB`.

## Rules

1. Migrations must be backwards compatible with the previous release (expand → migrate → contract),
   because they run while the old code is still live and `rollback` does not revert them.
2. Take a database backup before risky migrations ([backup.md](../11-runbooks/backup.md)).
3. A deploy takes about 5–10 minutes, most of it the frontend build on the single CPU.
4. `.env` changes do not need a deploy: edit `shared/.env`, then run
   `cd /var/www/autowave-platform/current && php8.4 artisan optimize && php8.4 artisan queue:restart` as
   `autowave`.

## First release (done once, 2026-09-29)

The first release was built by hand with the same steps before the script existed. To bootstrap the
script on a new server, clone the repository once and copy it:

```bash
cd /tmp && sudo -u autowave git clone --depth 1 git@github.com:akashwagh1703/autowave.git /tmp/aw
sudo -u autowave install -m 755 /tmp/aw/scripts/deploy.sh /var/www/autowave-platform/deploy.sh
rm -rf /tmp/aw
```

Keep these as separate lines: a `\` line break inside `sudo -iu autowave bash -c '…'` reaches git as an
extra argument ("fatal: Too many arguments").

CI-triggered deploys over SSH are the next step ([ci-cd.md](ci-cd.md)).
