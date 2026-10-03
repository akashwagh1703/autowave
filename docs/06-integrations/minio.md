# MinIO (object storage for uploads)

- **Status:** ✅ Website and product images can be stored on MinIO (`media` disk). ✅ Customer and student
  documents can be stored in a private bucket (`files` disk). ✅ Product, service and course videos and
  brochures, and the website's Video and Downloads sections, go with the website images (public bucket).
- **Last updated:** 2026-10-06

Uploaded images go to the disk named by `WEBSITE_MEDIA_DISK`: `public` (the server's own disk, served from
`/storage`) or `media` (an S3-compatible bucket, MinIO on production). Documents go to the disk named by
`FILES_PRIVATE_DISK`: `local` (the server's private `storage/app/private`) or `files` (a private MinIO bucket).
Each `media` and `attachments` row remembers its disk, so files can be moved between disks without breaking links.

## How it fits together

```text
AutoWave (PHP) ── PutObject / DeleteObject ──► http://127.0.0.1:9000/autowave-public/...   (MinIO, loopback)
Browser ── GET https://media.autowave.co.in/tenant/... ──► Nginx (read-only) ──► MinIO /autowave-public/...

Browser ── upload / open a document ──► AutoWave (permission check) ──► http://127.0.0.1:9000/autowave-private/...
```

- **Bucket `autowave-public`** holds only public files (logos, website images, product images). Anonymous
  read (`GetObject`) is allowed; nothing private may go in it.
- **Bucket `autowave-private`** holds documents (ID proofs, marksheets, contracts). It has no anonymous access
  and no public address: files are uploaded through AutoWave and opened through AutoWave after a permission
  check, so a leaked link is useless to anyone not signed in to that business.
- **AutoWave's own access key** can read, write and delete only those two buckets. The MinIO root login is never
  used by the app.
- Uploads pass through PHP rather than going straight from the browser to MinIO. Nginx buffers request bodies
  anyway, the file's type is checked from its content before it is stored, and MinIO needs no second public
  address or CORS setup.
- **`media.autowave.co.in`** is an Nginx site (covered by the wildcard certificate and the `*` DNS record)
  that allows only GET/HEAD, maps `/` to the bucket and hides bucket listings. `media` is a reserved
  subdomain, so no business can take it.
- Objects are written with `Cache-Control: public, max-age=31536000, immutable` (names are unique).
- `php artisan autowave:health` writes, reads and deletes a test object on each S3 disk in use. It fails when
  `MEDIA_URL` is missing or when the private disk points at a public bucket.

## Environment

| Variable | Production | Meaning |
|---|---|---|
| `WEBSITE_MEDIA_DISK` | `media` | Disk for new uploads (`public` until MinIO is ready) |
| `MEDIA_ACCESS_KEY` / `MEDIA_SECRET_KEY` | AutoWave's own MinIO key | Secret; never the root login |
| `MEDIA_BUCKET` | `autowave-public` | Public bucket |
| `MEDIA_ENDPOINT` | `http://127.0.0.1:9000` | Where the app talks to MinIO (loopback; no TLS needed) |
| `MEDIA_URL` | `https://media.autowave.co.in` | Public base URL for browsers |
| `MEDIA_REGION` | `us-east-1` | Any value works for MinIO |
| `FILES_PRIVATE_DISK` | `files` | Disk for documents (`local` until MinIO is ready) |
| `MEDIA_PRIVATE_BUCKET` | `autowave-private` | Private bucket; same key and endpoint as the media disk |
| `FILES_PUBLIC_DISK` | unset | Disk for catalog videos and brochures; when unset it follows `WEBSITE_MEDIA_DISK` |
| `FILES_QUOTA_MB` | `1024` | Default storage allowance per business (images and documents together); Super Admin → Tenants can change it per business |

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
docker exec minio mc mb --ignore-existing local/autowave-private
docker exec minio mc anonymous set none local/autowave-private

docker exec -i minio sh -c 'cat > /tmp/autowave-media.json' <<'EOF'
{
  "Version": "2012-10-17",
  "Statement": [
    {"Effect": "Allow", "Action": ["s3:GetBucketLocation", "s3:ListBucket"], "Resource": ["arn:aws:s3:::autowave-public", "arn:aws:s3:::autowave-private"]},
    {"Effect": "Allow", "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"], "Resource": ["arn:aws:s3:::autowave-public/*", "arn:aws:s3:::autowave-private/*"]}
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

### 4b. Documents in the private bucket

If step 2 was done before the private bucket existed, add it to the policy (the key and user stay the same):

```bash
docker exec minio sh -c 'mc alias set local http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD"'
docker exec minio mc mb --ignore-existing local/autowave-private
docker exec minio mc anonymous set none local/autowave-private
# Re-create /tmp/autowave-media.json with both buckets as in step 2, then:
docker exec minio mc admin policy remove local autowave-media || true
docker exec minio mc admin policy create local autowave-media /tmp/autowave-media.json
docker exec minio mc admin policy attach local autowave-media --user autowave-app
```

Then point documents at it, add the new `documents` permissions to existing businesses, and check:

```bash
ENV=/var/www/autowave-platform/shared/.env
sed -i -E '/^(FILES_PRIVATE_DISK|MEDIA_PRIVATE_BUCKET)=/d' "$ENV"
printf 'FILES_PRIVATE_DISK=files\nMEDIA_PRIVATE_BUCKET=autowave-private\n' >> "$ENV"

cd /var/www/autowave-platform/current
sudo -u autowave php8.4 artisan db:seed --class=RbacSeeder --force
sudo -u autowave php8.4 artisan db:seed --class=TenantBackfillSeeder --force
sudo -u autowave php8.4 artisan optimize
sudo -u autowave php8.4 artisan queue:restart
sudo -u autowave php8.4 artisan autowave:health          # "Private files ... files (bucket autowave-private)"
curl -s -o /dev/null -w 'anonymous: %{http_code}\n' http://127.0.0.1:9000/autowave-private/   # 403
```

Documents are up to 10 MB, within the current Nginx and PHP upload limits (20 MB).

### 4c. Raise the upload limit for videos

Catalog and website videos are up to 50 MB. Until the limits below are raised, a video upload over 20 MB is refused by
Nginx (413) before it reaches AutoWave:

```bash
# Nginx: in the AutoWave server blocks (or http {}), then test and reload
sudo grep -rn client_max_body_size /etc/nginx/sites-enabled/
#   client_max_body_size 60m;
sudo nginx -t && sudo systemctl reload nginx

# PHP-FPM: find the pool's php.ini and set the two values
php8.4 --ini | grep "Loaded Configuration"     # the CLI file; the FPM one is /etc/php/8.4/fpm/php.ini
#   upload_max_filesize = 60M
#   post_max_size = 64M
sudo systemctl reload php8.4-fpm
```

### 5. Close MinIO's public ports (after checking the other projects)

MinIO's API (9000) and console (9001) are published on all interfaces, and Docker bypasses UFW (AW-067).
AutoWave only needs `127.0.0.1:9000`. Before binding the ports to `127.0.0.1`, confirm that the micro-SaaS and
playltp don't hand out `http://<server-ip>:9000/...` links to browsers or use port 9000 from another server.
Re-creating the container (same image, same `/mnt/minio-data`) is also when to set a new root password; check
first whether those projects log in with the root user.

## Backups

The buckets are not backed up yet (AW-066). Include `/mnt/minio-data` (or `mc mirror` of `local/autowave-public`
and `local/autowave-private`) in the nightly backup together with the database. Documents are personal data:
keep the backup encrypted.

## Not yet

- Inbox attachments.
- EXIF stripping and resized variants (AW-035).
