# File Security

> Status: website and product images through `ManageMedia`; customer and student documents (private disk)
> and inbox files (private disk), and product, service and course videos and brochures and the website's
> Video and Downloads sections (public disk) through `ManageAttachments`.

## Storage layout

```text
tenant/{tenant_id}/logo/                   logo-{ulid}.{png|jpg|webp}
tenant/{tenant_id}/website/                hero-{ulid}.*, gallery-{ulid}.*
tenant/{tenant_id}/products/               product-{ulid}.*
tenant/{tenant_id}/documents/customers/    {ulid}.{pdf|docx|xlsx|jpg|png}   (private disk)
tenant/{tenant_id}/inbox/                  {ulid}.{jpg|png|webp|pdf|docx|xlsx|mp4|webm|ogg|mp3|m4a|aac|amr}   (private disk)
tenant/{tenant_id}/catalog/products/       {ulid}.{mp4|webm|pdf|docx|xlsx|jpg|png}   (public disk)
tenant/{tenant_id}/catalog/services/       (same)
tenant/{tenant_id}/catalog/courses/        (same)
tenant/{tenant_id}/website/videos/         {ulid}.{mp4|webm}                (public disk)
tenant/{tenant_id}/website/downloads/      {ulid}.{pdf|docx|xlsx|jpg|png}   (public disk)
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
- Images count against the business's storage allowance (below).

## Documents (implemented in `App\Domain\Files\Actions\ManageAttachments`)

Documents attached to customers (and shown on their student pages) are `attachments` rows
(`config/files.php`). They are private: stored on `config('files.disks.private')` (env `FILES_PRIVATE_DISK`:
`local` = `storage/app/private`, or `files` = MinIO bucket `autowave-private`) and never given a storage URL.

- **Type from content:** the type is read from the file's bytes (`finfo`), never from its name or the browser.
  Word and Excel files are ZIP archives, so their inner layout (`[Content_Types].xml` plus `word/document.xml`
  or `xl/workbook.xml`) is checked too. Allowed: PDF, DOCX, XLSX, JPG, PNG. Macro-enabled Office files, HTML,
  SVG, scripts and plain ZIPs are refused. The stored extension comes from the detected type.
- **Limits:** 10 MB per document, 50 files per customer, and the business's storage allowance.
- **Names:** the stored name is a ULID; the original name and an optional title (150 chars) are for display.
  Download names are sanitised and always end with the stored extension.
- **Opening a file** goes through `GET /attachments/{id}` (`AttachmentController`). It checks the tenant
  (route model binding is tenant-scoped, so another business gets 404), then the permission the owning record
  type requires (`documents.view` for customers). Responses send `X-Content-Type-Options: nosniff`,
  `Cache-Control: private, no-store` and a restrictive `Content-Security-Policy` (`sandbox` for everything but
  PDFs, which browsers refuse to show in a sandbox). PDFs, images and videos open in the browser; Word and
  Excel always download.
- **Permissions:** `documents.view` to see and open, `documents.manage` to upload and delete. Uploads are
  rate-limited (60 per minute per user).
- **Audit:** `attachment.uploaded`, `attachment.downloaded`, `attachment.deleted`.
- If storage is unreachable, an upload fails with a form error and nothing is saved; opening a file returns 404.

## Catalog videos and brochures

Products, services and courses can each have one video (MP4 or WebM, up to 50 MB, type read from the bytes)
and up to three documents (same rules as above). These files are meant for the public website, so they are
stored with `visibility = public` on `config('files.disks.public')` (env `FILES_PUBLIC_DISK`, falling back to
`WEBSITE_MEDIA_DISK`) and the website links to them directly. Never put anything private on a catalog item.

- **Permissions:** uploading and deleting need `products.update`, `services.manage` or `courses.manage`;
  `GET /attachments/{id}` redirects to the public URL (or downloads with `?download=1`) for anyone who can
  view the item in the app.
- **Website:** `WebsiteContent` only reads `public` attachments, so private customer documents can never
  appear on a website even if one were linked to a catalog item.
- **Deleting** a product, service or course (one at a time or in bulk) deletes its files too.

The website's **Video** section (up to three videos) and **Downloads** section (up to ten documents) work the
same way: public files owned by the `website_sections` row, uploaded with `website.manage`, opened with
`website.view`, and deleted when the section is removed.

## Inbox files

Each conversation message can carry one private file (owner `conversation_message`): photos (JPG, PNG,
WebP, 5 MB), documents (10 MB), videos and voice notes (OGG, MP3, M4A, AAC, AMR; 16 MB, WhatsApp's limit).
Opening one needs `conversations.view`; attaching one to a reply needs `conversations.reply`.

Files contacts send are downloaded by `DownloadInboundMedia` on the media queue and then go through the
same content checks and storage allowance as an upload. The download itself is restricted: HTTPS only, only
from Meta's media hosts (`messaging.meta.media_hosts`, re-checked on every redirect), and never more than
16 MB (declared size, `Content-Length` and the body are all checked). The WhatsApp token is only sent to
those hosts. A file that fails a check is not stored; the message keeps its `[Image]` text with the reason.

## Storage allowance

`App\Domain\Files\Support\StorageAllowance` adds up `media.size_bytes` and `attachments.size_bytes` for the
business and refuses an upload that would go over the allowance: `FILES_QUOTA_MB` (default 1024 MB), or the
per-business value a platform admin sets in Super Admin → Tenants (tenant setting `storage_quota`, audited as
`storage.limit_updated`). Lowering it never deletes files; new uploads are refused until there is room.

## Still to do

- Strip EXIF metadata and create resized variants on the media queue (AW-035).
- Malware scanning of uploaded documents (for example ClamAV on the media queue).
