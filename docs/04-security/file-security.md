# File Security

> Status: Phase 6 adds the first uploads: website images (logo, hero image, gallery) through `ManageMedia`.

## Storage layout

```text
tenant/{tenant_id}/logo/        logo-{ulid}.{png|jpg|webp}
tenant/{tenant_id}/website/     hero-{ulid}.*, gallery-{ulid}.*
tenant/{tenant_id}/products/    (Phase 7)
tenant/{tenant_id}/documents/   (later, private disk)
```

Website images go on `config('website.media.disk')` (env `WEBSITE_MEDIA_DISK`, default `public`):

- `public`: served from `/storage/...` on each tenant's own host, which needs `php artisan storage:link`.
- `media`: MinIO bucket `autowave-public`, served read-only from `MEDIA_URL` (`https://media.autowave.co.in`).
  The bucket allows anonymous reads, so it must only ever hold public files. The app uses its own access key
  limited to that bucket ([minio.md](../06-integrations/minio.md)).

If storage is unreachable, an upload fails with a form error and nothing is saved; a failed file delete is
reported and the row is still removed.

## Rules (implemented in `App\Domain\Media\Actions\ManageMedia`)

- **Allowed types:** JPEG, PNG and WebP only. Both the extension (`mimes`) and the sniffed content type
  (`mimetypes`) must match. The stored extension comes from the sniffed type, not the client's file name.
- **SVG is refused** because it can carry scripts. HTML and other types are refused too.
- **Limits:** size up to `max_kb` (4 MB); dimensions from 100 to 6000 px per side; per-collection limits
  (logo 1, hero 1, gallery 24).
- **Names:** filenames are generated on the server from a ULID. The client's file name is stored for display
  only and never used in a path.
- **Tenant checks:** `media` rows carry `tenant_id` (`BelongsToTenant`, fail-closed). Route model binding for
  `{media}` resolves only the current tenant's rows. Deleting a row also deletes its file.
- **Permissions:** uploading, describing, reordering and deleting images need `website.manage`. Uploads are
  also rate-limited (60 per minute per user).
- Every upload and deletion is audited (`media.uploaded`, `media.deleted`).

## Still to do

- Strip EXIF metadata and create resized variants on the media queue (AW-035).
- Private documents on a private bucket, served through authorised controllers or short-lived signed URLs.
- Videos and documents: direct browser uploads with signed upload links, then a server-side type and size check.
