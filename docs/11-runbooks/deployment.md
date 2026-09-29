# Runbook: Deployment

- **Last updated:** 2026-09-30 (validated in production)
- **Details:** [docs/09-devops/deployment.md](../09-devops/deployment.md)

1. Confirm CI is green on the commit to deploy and that it is pushed to GitHub (`master`).
2. Read `CHANGELOG.md` for risky migrations. If any: take a manual backup first ([backup.md](backup.md)).
3. On the server, as root or a sudoer:

   ```bash
   sudo -iu autowave /var/www/autowave-platform/deploy.sh
   ```

   It ends with `==> Deployed <sha> (master)` and `https://app.autowave.co.in/up answered 200`. On
   failure before the switch the live site is untouched; read the error, fix, deploy again.
4. Verify:

   ```bash
   cd /var/www/autowave-platform/current
   sudo -u autowave php8.4 artisan autowave:health
   systemctl is-active autowave-platform-worker
   sudo -u autowave php8.4 artisan queue:failed | head
   tail -n 50 storage/logs/laravel-$(date +%F).log 2>/dev/null
   ```

5. Smoke test in a private window: <https://admin.autowave.co.in> login, <https://app.autowave.co.in>
   dashboard, one tenant website.
6. If anything is wrong → [rollback.md](rollback.md).
7. `deployments.log` records every deploy; add a CHANGELOG line for notable releases.
