# Runbook: Domain Mapping

> Domain resolution is not implemented yet (Phase 1). Update this runbook when it is.

## Tenant subdomain (`abc-salon.autowave.in`) not loading

1. DNS: `dig +short abc-salon.autowave.in` → VPS IP (wildcard `*.autowave.in` A record).
2. TLS: `curl -vI https://abc-salon.autowave.in` — certificate covers `*.autowave.in`?
3. Nginx: host matches `server_name *.autowave.in`; `sudo nginx -t`.
4. App: row exists in `domains` with `domain = 'abc-salon.autowave.in'`, status active, tenant active.
5. Cache: clear domain resolution cache after changes (`php artisan cache:clear` or the targeted key).

## Custom domain (`www.abcsalon.com`)

1. Customer DNS: CNAME/A record points to AutoWave as instructed.
2. Verification: `domains.verified_at` set (TXT/HTTP verification passed).
3. Certificate issued: `domains.ssl_status = active` (see `ssl-renewal.md`).
4. Only one tenant may own a domain (unique index). Remapping is a Super Admin action and is audit-logged.
