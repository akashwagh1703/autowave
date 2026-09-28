# Supervisor

> Draft (AW-004). Until Horizon is added (AW-001), run `queue:work` programs directly.
> Since Phase 5, automations and messages need the high-priority worker **and** the scheduler cron below.
> See [queue-workers.md](queue-workers.md). The jobs set their own tries and backoff, which take
> precedence over the CLI flags.

`/etc/supervisor/conf.d/autowave-worker.conf`:

```ini
[program:autowave-worker-high]
command=php /var/www/autowave/current/artisan queue:work redis --queue=automation,messaging,notifications --sleep=1 --tries=3 --backoff=10 --max-time=3600
user=autowave
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/www/autowave/shared/storage/logs/worker-high.log

[program:autowave-worker-default]
command=php /var/www/autowave/current/artisan queue:work redis --queue=default,ai,reports,media --sleep=3 --tries=3 --backoff=30 --max-time=3600
user=autowave
numprocs=1
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/www/autowave/shared/storage/logs/worker-default.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status
```

Scheduler (cron for user `autowave`):

```cron
* * * * * cd /var/www/autowave/current && php artisan schedule:run >> /dev/null 2>&1
```

When Horizon is added, replace the worker programs with a single `php artisan horizon` program.
