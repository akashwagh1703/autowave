# SSL / TLS

- **Last updated:** 2026-09-30 (production certificate issued, AW-004)

## Platform and tenant subdomains

One Let's Encrypt certificate covers `autowave.co.in` and `*.autowave.co.in`. Wildcards need the DNS-01
challenge, so certbot creates a temporary TXT record through the DigitalOcean API. This requires the
domain's DNS to be hosted at DigitalOcean (nameservers `ns1-3.digitalocean.com`).

### API token

DigitalOcean → API → Tokens → Generate New Token: name `certbot-autowave`, **no expiry** (an expired token
stops renewals), scope **domain** (create, read, update, delete) or Full Access. Store it in a root-only file:

```bash
apt install -y python3-certbot-dns-digitalocean
mkdir -p /root/.secrets && chmod 700 /root/.secrets
nano /root/.secrets/digitalocean.ini      # one line: dns_digitalocean_token = dop_v1_...
chmod 600 /root/.secrets/digitalocean.ini
awk -F' = ' '{print $1 " = [hidden], length " length($2)}' /root/.secrets/digitalocean.ini   # expect 71
```

The file needs the `dns_digitalocean_token = ` prefix; a bare token fails with "matched as neither
section nor keyword" and certbot then prints the token in its error — rotate it if that happens. Some
terminals (the DigitalOcean web console) cannot paste into `read -s`; use nano.

### Issue

```bash
certbot certonly --dns-digitalocean \
  --dns-digitalocean-credentials /root/.secrets/digitalocean.ini \
  --dns-digitalocean-propagation-seconds 60 \
  -d autowave.co.in -d '*.autowave.co.in'
```

Files: `/etc/letsencrypt/live/autowave.co.in/{fullchain,privkey}.pem`. Nginx: [nginx.md](nginx.md).

### Renewal

Automatic via the certbot systemd timer (the other certificates on the server use the nginx plugin and
renew the same way). Reload Nginx after each renewal:

```bash
printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
certbot renew --dry-run --cert-name autowave.co.in
```

Runbook: [ssl-renewal.md](../11-runbooks/ssl-renewal.md).

## Custom domains (future)

Per-domain certificates issued only after the domain is verified in `domains` (status verified).
Options to decide in an ADR when custom domains are built: certbot HTTP-01 per domain triggered by a queued job,
or Caddy/on-demand TLS in front of Nginx with an "ask" endpoint that checks the `domains` table.
`domains.ssl_status` tracks certificate state.
