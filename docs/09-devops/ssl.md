# SSL / TLS

> Draft (AW-004).

## Platform + tenant subdomains

Wildcard certificate for `autowave.in` and `*.autowave.in` via Let's Encrypt DNS-01 challenge (wildcards
require DNS validation), e.g. certbot with the DigitalOcean DNS plugin:

```bash
sudo apt install certbot python3-certbot-dns-digitalocean
# /root/.secrets/digitalocean.ini: dns_digitalocean_token = <token>  (chmod 600)
sudo certbot certonly --dns-digitalocean --dns-digitalocean-credentials /root/.secrets/digitalocean.ini \
  -d autowave.in -d '*.autowave.in'
```

Renewal is automatic via the certbot systemd timer; reload Nginx in a deploy hook:
`/etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh` → `systemctl reload nginx`.

## Custom domains (future)

Per-domain certificates issued only after the domain is verified in `domains` (status verified).
Options to decide in an ADR when custom domains are built: certbot HTTP-01 per domain triggered by a queued job,
or Caddy/on-demand TLS in front of Nginx with an "ask" endpoint that checks the `domains` table.
`domains.ssl_status` tracks certificate state.

Runbook: `docs/11-runbooks/ssl-renewal.md`.
