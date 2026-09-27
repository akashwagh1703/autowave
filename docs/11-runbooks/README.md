# Runbooks

Step-by-step operational procedures so anyone can operate production without the original developer.

> All runbooks are drafts until validated on the first real server (AW-004).

| Runbook | When |
|---|---|
| [deployment.md](deployment.md) | Shipping a release |
| [rollback.md](rollback.md) | A release is broken |
| [backup.md](backup.md) | Configuring/verifying backups |
| [restore-database.md](restore-database.md) | Data loss or corruption |
| [queue-failure.md](queue-failure.md) | Jobs stuck, failing or not processing |
| [redis-failure.md](redis-failure.md) | Redis down or out of memory |
| [database-failure.md](database-failure.md) | PostgreSQL down or unhealthy |
| [domain-mapping.md](domain-mapping.md) | Tenant domain not resolving |
| [ssl-renewal.md](ssl-renewal.md) | Certificate expiring/expired |
| [messaging-webhook.md](messaging-webhook.md) | WhatsApp/Instagram webhooks failing |

First diagnostic for almost everything: `php artisan autowave:health` and `storage/logs/laravel-*.log`.
