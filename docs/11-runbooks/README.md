# Runbooks

Step-by-step operational procedures so anyone can operate production without the original developer.

> Updated for the production server on 2026-09-30 (AW-004). Deployment, rollback, SSL and domain steps
> were run for real; the failure and restore runbooks use the real names but have not been exercised.

| Runbook | When |
|---|---|
| [deployment.md](deployment.md) | Shipping a release |
| [rollback.md](rollback.md) | A release is broken |
| [enable-integrations.md](enable-integrations.md) | Turning on email, AI, WhatsApp and Instagram in production |
| [backup.md](backup.md) | Configuring/verifying backups |
| [restore-database.md](restore-database.md) | Data loss or corruption |
| [queue-failure.md](queue-failure.md) | Jobs stuck, failing or not processing |
| [redis-failure.md](redis-failure.md) | Redis down or out of memory |
| [database-failure.md](database-failure.md) | PostgreSQL down or unhealthy |
| [domain-mapping.md](domain-mapping.md) | Tenant domain not resolving |
| [ssl-renewal.md](ssl-renewal.md) | Certificate expiring/expired |
| [messaging-webhook.md](messaging-webhook.md) | WhatsApp/Instagram webhooks failing |

First diagnostic for almost everything, on the server:

```bash
cd /var/www/autowave-platform/current
sudo -u autowave php8.4 artisan autowave:health
tail -n 50 storage/logs/laravel-*.log
```
