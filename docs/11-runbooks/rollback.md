# Runbook: Rollback

- **Last updated:** 2026-09-30

## Code rollback (no destructive migration)

```bash
sudo -iu autowave /var/www/autowave-platform/deploy.sh releases    # the live one is marked
sudo -iu autowave /var/www/autowave-platform/deploy.sh rollback
sudo -u autowave php8.4 /var/www/autowave-platform/current/artisan autowave:health
```

`rollback` switches `current` to the release before the live one, re-caches its config (so `.env` edits
made since then apply), restarts the queue worker and smoke-tests `/up`. Run it again to go back further
(the last 5 releases are kept). To return to the newest release, deploy again.

By hand, if the script is unavailable:

```bash
cd /var/www/autowave-platform
ls -1d releases/* | sort -r                        # newest first
PREV=$(ls -1d releases/* | sort -r | sed -n 2p)
sudo -u autowave bash -c "cd $PREV && php8.4 artisan optimize"
sudo -u autowave ln -sfn "$PWD/$PREV" current.new && sudo -u autowave mv -Tf current.new current
sudo -u autowave php8.4 current/artisan queue:restart
```

## Migration rollback

Only if the release's migrations are reversible and no new data depends on them:

```bash
sudo -u autowave php8.4 artisan migrate:rollback --step=<n> --force   # from the NEW release, before switching back
```

If a migration was destructive, restore from backup instead (`restore-database.md`) — this loses data written
since the backup; get owner approval.

## After

Open an issue (`AW-XXX`, category Bug), add to `docs/00-overview/known-issues.md`, and write a short incident note.
