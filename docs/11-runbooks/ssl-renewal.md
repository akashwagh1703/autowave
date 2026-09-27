# Runbook: SSL Renewal

## Check

```bash
sudo certbot certificates                                  # expiry dates
echo | openssl s_client -connect app.autowave.in:443 -servername app.autowave.in 2>/dev/null | openssl x509 -noout -dates
systemctl list-timers | grep certbot
```

## Renew

```bash
sudo certbot renew --dry-run          # test
sudo certbot renew                    # real
sudo nginx -t && sudo systemctl reload nginx
```

## Wildcard renewal failing

Wildcards use DNS-01: check the DigitalOcean API token in `/root/.secrets/digitalocean.ini` is valid and
has write access to the `autowave.in` DNS zone. Check `/var/log/letsencrypt/letsencrypt.log`.

## Custom domains (future)

A domain whose DNS no longer points to AutoWave will fail renewal: mark `domains.ssl_status = failed`, notify the
tenant, and deactivate after the grace period (prevents dangling-domain issues).
