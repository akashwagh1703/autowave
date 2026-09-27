# Runbook: Deployment

1. Confirm CI is green on the commit to deploy (`main`).
2. Read `CHANGELOG.md` / PR for risky migrations. If any: take a manual backup first (`backup.md` → manual backup).
3. SSH to the server as `autowave` and run the deploy script (`docs/09-devops/deployment.md`).
4. Verify:
   ```bash
   cd /var/www/autowave/current
   php artisan autowave:health
   curl -fsS https://app.autowave.in/up
   sudo supervisorctl status
   php artisan queue:failed | head
   tail -n 50 storage/logs/laravel-$(date +%F).log
   ```
5. Smoke test: log in, open dashboard, open one tenant website.
6. If anything is wrong → [rollback.md](rollback.md).
7. Record the deployment (date, commit, operator) in the release notes / CHANGELOG section.
