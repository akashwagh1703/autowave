# Process supervision

- **Last updated:** 2026-09-30

**Production uses systemd, not Supervisor.** The shared server already ran its other queue workers as
systemd units and Supervisor is not installed. The unit and the scheduler cron are in
[queue-workers.md](queue-workers.md#production).

When Horizon is added (AW-001), replace the worker unit's `ExecStart` with `php8.4 artisan horizon`.

## Supervisor alternative

On a server that uses Supervisor instead, the equivalent program (paths as in production):

```ini
[program:autowave-platform-worker]
command=/usr/bin/php8.4 /var/www/autowave-platform/current/artisan queue:work redis --queue=automation,messaging,notifications,default,ai,reports,media --sleep=3 --tries=3 --max-time=3600 --memory=128
user=autowave
numprocs=1
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/www/autowave-platform/shared/storage/logs/worker.log
```

On a larger server, split it into a high-priority program (`automation,messaging,notifications`,
`--sleep=1`, 2 processes) and a default one (`default,ai,reports,media`).
