# Runbook: Backups

PostgreSQL backups are mandatory. **Never keep the only backup on the same VPS.**

## Policy

| Item | Value |
|---|---|
| Frequency | Daily (02:00 IST) + before risky deploys |
| Format | `pg_dump -Fc` (custom, compressed) |
| Retention | 7 daily, 4 weekly, 6 monthly |
| Location | Off-server object storage (e.g. DigitalOcean Spaces, different region) |
| Encryption | At rest in the bucket; transfer over TLS |
| Restore test | Monthly into a scratch database |

## Daily backup script (draft)

`/usr/local/bin/autowave-backup.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail
TS=$(date +%Y%m%d-%H%M%S)
FILE=/var/backups/autowave/autowave-$TS.dump
mkdir -p /var/backups/autowave
pg_dump -Fc -U autowave -h 127.0.0.1 autowave > "$FILE"
s3cmd put "$FILE" s3://autowave-backups/postgres/        # or rclone/aws cli
find /var/backups/autowave -name '*.dump' -mtime +2 -delete   # keep only 2 days locally
```

Cron (root or `postgres`): `0 2 * * * /usr/local/bin/autowave-backup.sh >> /var/log/autowave-backup.log 2>&1`
Credentials via `~/.pgpass` (chmod 600) — never inline.

## Manual backup before a deploy

```bash
pg_dump -Fc -U autowave -h 127.0.0.1 autowave > /var/backups/autowave/pre-deploy-$(date +%F-%H%M).dump
```

## Verify

Check the bucket for today's file and its size; run the monthly restore test (`restore-database.md`).
Also back up `shared/.env` (securely, separately) and `shared/storage/app` (tenant uploads).
