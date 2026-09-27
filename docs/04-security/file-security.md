# File Security

> Status: no uploads exist yet.

## Storage layout

```text
tenant/{tenant_id}/logo/
tenant/{tenant_id}/website/
tenant/{tenant_id}/products/
tenant/{tenant_id}/documents/
```

## Rules

- Validate size, MIME type (content sniffing, not just extension), extension allow-list and image dimensions.
- Generate server-side filenames (UUID); never trust the client filename for storage paths.
- Public assets (logo, website images) may live on the public disk; private documents on a private disk,
  served only through authorized controllers or short-lived signed URLs.
- Authorization checks confirm the file's tenant matches the current tenant.
- Strip EXIF metadata from public images where feasible (media queue).
- Never allow SVG/HTML uploads to be served inline from the app domain without sanitisation.
