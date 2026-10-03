# MinIO (object storage for uploads)

- **Status:** ✅ Website and product images can be stored on MinIO (`media` disk). Videos and documents: not yet.
- **Last updated:** 2026-10-03

Uploaded images go to the disk named by `WEBSITE_MEDIA_DISK`: `public` (the server's own disk, served from
`/storage`) or `media` (an S3-compatible bucket, MinIO on production). Each `media` row remembers its disk,
so files can be moved between disks without breaking links.

## How it fits together

```text
AutoWave (PHP) ── PutObject / DeleteObject ──► http://127.0.0.1:9000/autowave-public/...   (MinIO, loopback)
Browser ── GET https://media.autowave.co.in/tenant/... ──► Nginx (read-only) ──► MinIO /autowave-public/...
```

- **Bucket `autowave-public`** holds only public files (logos, website images, product images). Anonymous
  read (`GetObject`) is allowed; nothing private may go in it.
- **AutoWave's own access key** can read, write and delete only that bucket. The MinIO root login is never
  used by the app.
- **`media.autowave.co.in`** is an Nginx site (covered by the wildcard certificate and the `*` DNS record)
  that allows only GET/HEAD, maps `/` to the bucket and hides bucket listings. `media` is a reserved
  subdomain, so no business can take it.
- Objects are written with `Cache-Control: public, max-age=31536000, immutable` (names are unique).
- `php artisan autowave:health` writes, reads and deletes a test object when the media disk is S3, and fails
  when `MEDIA_URL` is missing.

## Environment

| Variable | Production | Meaning |
|---|---|---|
| `WEBSITE_MEDIA_DISK` | `media` | Disk for new uploads (`public` until MinIO is ready) |
| `MEDIA_ACCESS_KEY` / `MEDIA_SECRET_KEY` | AutoWave's own MinIO key | Secret; never the root login |
| `MEDIA_BUCKET` | `autowave-public` | Public bucket |
| `MEDIA_ENDPOINT` | `http://127.0.0.1:9000` | Where the app talks to MinIO (loopback; no TLS needed) |
| `MEDIA_URL` | `https://media.autowave.co.in` | Public base URL for browsers |
| `MEDIA_REGION` | `us-east-1` | Any value works for MinIO |

## Production setup (run as root)

The MinIO container is shared with the older micro-SaaS and playltp. None of these steps changes their
data or credentials.

### 1. Check MinIO

```bash
docker inspect minio --format '{{.Config.Image}} | ports: {{json .HostConfig.PortBindings}} | restart: {{.HostConfig.RestartPolicy.Name}}'
df -h /mnt/minio-data
curl -s -o /dev/null -w 'health: %{http_code}\n' http://127.0.0.1:9000/minio/health/live
docker exec minio mc --version
```

Expect `health: 200`. If `mc` is not found in the container, stop here (the steps below need it).

MinIO no longer publishes community images on Docker Hub or quay.io (late 2025). The server already has the
image, so **do not `docker rmi` it**; re-creating the container from the same image works without a pull.

### 2. Bucket and AutoWave's own key

The root login is read from the container's own environment, so it is never typed or shown.

```bash
docker exec minio sh -c 'mc alias set local http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD"'
docker exec minio mc mb --ignore-existing local/autowave-public
docker exec minio mc anonymous set download local/autowave-public

docker exec -i minio sh -c 'cat > /tmp/autowave-media.json' <<'EOF'
{
  "Version": "2012-10-17",
  "Statement": [
    {"Effect": "Allow", "Action": ["s3:GetBucketLocation", "s3:ListBucket"], "Resource": ["arn:aws:s3:::autowave-public"]},
    {"Effect": "Allow", "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"], "Resource": ["arn:aws:s3:::autowave-public/*"]}
  ]
}
EOF
docker exec minio mc admin policy create local autowave-media /tmp/autowave-media.json

AW_SECRET=$(openssl rand -hex 24)
docker exec minio mc admin user add local autowave-app "$AW_SECRET"
docker exec minio mc admin policy attach local autowave-media --user autowave-app
```

Write the key straight into AutoWave's `.env` (nothing is printed):

```bash
ENV=/var/www/autowave-platform/shared/.env
sed -i -E '/^MEDIA_(ACCESS_KEY|SECRET_KEY|BUCKET|ENDPOINT|URL|REGION)=/d' "$ENV"
printf 'MEDIA_ACCESS_KEY=autowave-app\nMEDIA_SECRET_KEY=%s\nMEDIA_BUCKET=autowave-public\nMEDIA_ENDPOINT=http://127.0.0.1:9000\nMEDIA_URL=https://media.autowave.co.in\n' "$AW_SECRET" >> "$ENV"
unset AW_SECRET
```

### 3. `media.autowave.co.in` (Nginx)

`/etc/nginx/sites-available/autowave-media`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name media.autowave.co.in;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name media.autowave.co.in;

    ssl_certificate /etc/letsencrypt/live/autowave.co.in/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/autowave.co.in/privkey.pem;

    # No bucket listing.
    location = / {
        return 404;
    }

    location / {
        limit_except GET HEAD {
            deny all;
        }
        proxy_pass http://127.0.0.1:9000/autowave-public/;
        proxy_set_header Host 127.0.0.1:9000;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_hide_header x-amz-request-id;
        proxy_hide_header x-amz-id-2;
    }
}
```

```bash
ln -s /etc/nginx/sites-available/autowave-media /etc/nginx/sites-enabled/autowave-media
nginx -t && systemctl reload nginx
curl -s -o /dev/null -w 'listing: %{http_code}\n' https://media.autowave.co.in/          # 404
```

The exact `server_name` wins over the tenant-site wildcard (`*.autowave.co.in`).

### 4. Switch AutoWave over

Deploy a release that includes this change first (`deploy.sh deploy master`). Then:

```bash
ENV=/var/www/autowave-platform/shared/.env
sed -i 's/^WEBSITE_MEDIA_DISK=.*/WEBSITE_MEDIA_DISK=media/' "$ENV"
grep -q '^WEBSITE_MEDIA_DISK=' "$ENV" || echo 'WEBSITE_MEDIA_DISK=media' >> "$ENV"

cd /var/www/autowave-platform/current
sudo -u autowave php8.4 artisan optimize
sudo -u autowave php8.4 artisan queue:restart
sudo -u autowave php8.4 artisan autowave:health          # "Media storage ... media (bucket autowave-public)"
```

New uploads now go to MinIO. Move the existing images (copies, then switches each row; the old files stay):

```bash
sudo -u autowave php8.4 artisan autowave:media-move media --dry-run
sudo -u autowave php8.4 artisan autowave:media-move media
```

Check a business website and the product pages: image addresses start with `https://media.autowave.co.in/`.
After a few days without problems, confirm nothing is left on the local disk ("Would copy 0", "Missing
source file 0"), then delete the old local copies:

```bash
sudo -u autowave php8.4 artisan autowave:media-move media --dry-run
sudo -u autowave rm -rf /var/www/autowave-platform/shared/storage/app/public/tenant
```

To go back: set `WEBSITE_MEDIA_DISK=public`, `optimize`, then `autowave:media-move public`.

### 5. Close MinIO's public ports (after checking the other projects)

MinIO's API (9000) and console (9001) are published on all interfaces, and Docker bypasses UFW (AW-067).
AutoWave only needs `127.0.0.1:9000`. Before binding the ports to `127.0.0.1`, confirm that the micro-SaaS and
playltp don't hand out `http://<server-ip>:9000/...` links to browsers or use port 9000 from another server.
Re-creating the container (same image, same `/mnt/minio-data`) is also when to set a new root password; check
first whether those projects log in with the root user.

## Backups

The bucket is not backed up yet (AW-066). Include `/mnt/minio-data` (or `mc mirror local/autowave-public`) in the
nightly backup together with the database.

## Not yet

- Videos and documents: planned as direct browser-to-MinIO uploads with short-lived signed upload links, a
  private bucket for documents, and a storage allowance per business.
- EXIF stripping and resized variants (AW-035).
