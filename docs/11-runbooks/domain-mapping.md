# Runbook: Domain Mapping

> Domain resolution is implemented (Phase 1, `App\Domain\Domain\Services\DomainResolver`). Custom-domain
> verification and SSL issuance are not automated yet.

## Tenant subdomain (`abc-salon.autowave.co.in`) not loading

1. DNS: `dig +short abc-salon.autowave.co.in` → `168.144.121.155` (wildcard `*` A record in DigitalOcean DNS).
   A browser that still gets another address (GoDaddy's parking page at `/lander`) has an old cached
   answer: `ipconfig /flushdns`, clear the browser's host cache, or wait for the TTL.
2. TLS: `curl -vI https://abc-salon.autowave.co.in` — the certificate covers `*.autowave.co.in`.
3. Nginx: host matches `server_name *.autowave.co.in`; `sudo nginx -t`.
4. App: row exists in `domains` with `domain = 'abc-salon.autowave.co.in'` (lowercase), `status = 'active'`,
   and the tenant's `status = 'active'`. The `website` module must be enabled, otherwise the site 404s.
5. Cache: lookups (including misses) are cached for `AUTOWAVE_DOMAIN_CACHE_TTL` seconds under
   `domain-resolver:{host}`. Saving a `Domain` model or suspending/activating a tenant flushes it; after manual
   SQL changes run `php artisan tinker --execute="app(App\Domain\Domain\Services\DomainResolver::class)->forget('abc-salon.autowave.co.in');"`.

## Custom domain (`www.abcsalon.com`)

1. Customer DNS: CNAME/A record points to AutoWave as instructed.
2. Verification: `domains.verified_at` set (TXT/HTTP verification passed).
3. Certificate issued: `domains.ssl_status = active` (see `ssl-renewal.md`).
4. Only one tenant may own a domain (unique index). Remapping is a Super Admin action and is audit-logged.
