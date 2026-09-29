# Runbook: Backups

PostgreSQL backups are mandatory. **Never keep the only backup on the same VPS.**

> **Not automated yet in production (AW-066).** Production database: `autowave_platform` (role
> `autowave_platform_user`) on the server's PostgreSQL 16. Until the daily job exists, take manual dumps
> before risky deploys and copy them off the server.

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
sudo -u postgres pg_dump -Fc autowave_platform > "$FILE"
s3cmd put "$FILE" s3://autowave-backups/postgres/        # or rclone/aws cli
find /var/backups/autowave -name '*.dump' -mtime +2 -delete   # keep only 2 days locally
```

Cron (root): `0 2 * * * /usr/local/bin/autowave-backup.sh >> /var/log/autowave-backup.log 2>&1`
`sudo -u postgres` uses local peer authentication, so no database password is stored for backups.

## Manual backup before a deploy

```bash
mkdir -p /var/backups/autowave
sudo -u postgres pg_dump -Fc autowave_platform > /var/backups/autowave/pre-deploy-$(date +%F-%H%M).dump
```

Copy it to your machine: `scp root@168.144.121.155:/var/backups/autowave/pre-deploy-*.dump .`

## Verify

Check the bucket for today's file and its size; run the monthly restore test (`restore-database.md`).
Also back up `/var/www/autowave-platform/shared/.env` (securely, separately) and
`/var/www/autowave-platform/shared/storage/app` (tenant uploads).
