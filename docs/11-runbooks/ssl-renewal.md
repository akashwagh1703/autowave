# Runbook: SSL Renewal

## Check

```bash
sudo certbot certificates                                  # expiry dates (autowave.co.in covers *.autowave.co.in)
echo | openssl s_client -connect app.autowave.co.in:443 -servername app.autowave.co.in 2>/dev/null | openssl x509 -noout -dates
systemctl list-timers | grep certbot
```

The first certificate was issued on 2026-09-29 and expires on 2026-12-28; certbot renews it about 30 days
before expiry.

## Renew

```bash
sudo certbot renew --dry-run          # test
sudo certbot renew                    # real
sudo nginx -t && sudo systemctl reload nginx
```

## Wildcard renewal failing

Wildcards use DNS-01: check the DigitalOcean API token in `/root/.secrets/digitalocean.ini` is valid (not
expired or deleted) and has write access to the `autowave.co.in` DNS zone, and that the domain's nameservers
are still DigitalOcean's. Check `/var/log/letsencrypt/letsencrypt.log`.

To replace the token: create a new one (no expiry, domain scope), write it with nano as
`dns_digitalocean_token = dop_v1_...`, delete the old token in DigitalOcean, then
`certbot renew --dry-run --cert-name autowave.co.in`. Setup details: [ssl.md](../09-devops/ssl.md).

## Custom domains (future)

A domain whose DNS no longer points to AutoWave will fail renewal: mark `domains.ssl_status = failed`, notify the
tenant, and deactivate after the grace period (prevents dangling-domain issues).
