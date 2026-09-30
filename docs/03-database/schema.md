# Database Schema

Engine: PostgreSQL (local Docker 17; shared dev server currently **11.20** — migrations stay PG11-compatible,
see AW-006). Timestamps in UTC. Update this file with every migration.

## Laravel defaults (Phase 0)

| Table | Migration | Purpose |
|---|---|---|
| `users` | `0001_01_01_000000_create_users_table` (+ `2026_09_27_150000`) | Accounts: name, email unique, password, `status` (`active\|suspended`, indexed), `is_platform_admin`, `last_login_at` |
| `password_reset_tokens` | same | Password reset tokens (email PK) |
| `sessions` | same | Database-backed sessions |
| `cache`, `cache_locks` | `0001_01_01_000001` | Database cache fallback (unused while `CACHE_STORE=redis`) |
| `jobs`, `job_batches`, `failed_jobs` | `0001_01_01_000002` | Queue tables (Redis queues; batches and failures still use DB) |

## Platform catalogue (Phase 1, `2026_09_27_150100`) — not tenant-owned

| Table | Key columns |
|---|---|
| `business_types` | `code`, `version` (unique together), `name`, `status`, `is_public`, `configuration` jsonb, `sort_order` |
| `engines` | `code` unique, `status`, `configuration` jsonb (`requires_modules`) |
| `modules` | `code` unique, `type`, `version`, `status`, `configuration` jsonb |
| `module_dependencies` | PK (`module_id`, `depends_on_module_id`) |
| `business_type_engines` | PK (`business_type_id`, `engine_id`) |
| `business_type_modules` | PK (`business_type_id`, `module_id`), `enabled`, `configuration` |

## Tenancy (Phase 1, `2026_09_27_150200`)

| Table | Key columns |
|---|---|
| `tenants` | `name`, `slug` (63, unique), `business_type_id` (restrict), `business_type_version`, `status`, `is_internal`, `timezone`, `locale`, `currency`, `created_by_user_id` (nullable, null on delete, indexed — Phase 2 `2026_09_27_160000`) |
| `tenant_users` | `tenant_id`, `user_id` (unique together), `status`, `joined_at`; unique (`id`, `tenant_id`) for composite FKs |
| `tenant_settings` | `tenant_id`, `key` (unique together), `value` jsonb — tenant-scoped model |
| `tenant_engines` | `tenant_id`, `engine_id` (unique together), `enabled` |
| `tenant_modules` | `tenant_id`, `module_id` (unique together), `enabled`, `version` (pinned), `configuration` |

## RBAC (Phase 1, `2026_09_27_150300`)

| Table | Key columns |
|---|---|
| `permissions` | `key` unique, `group`, `description` |
| `roles` | `tenant_id` nullable (null = template), `slug`, `name`, `grants_all`, `is_locked`; unique (`tenant_id`, `slug`), unique (`id`, `tenant_id`), partial unique `roles_template_slug_unique (slug) WHERE tenant_id IS NULL` |
| `role_permissions` | PK (`role_id`, `permission_id`) |
| `user_roles` | `tenant_id`, `tenant_user_id`, `role_id`; composite FKs `(tenant_user_id, tenant_id) → tenant_users(id, tenant_id)` and `(role_id, tenant_id) → roles(id, tenant_id)`; unique (`tenant_user_id`, `role_id`) |

## Domains (Phase 1, `2026_09_27_150400`)

`domains`: `tenant_id`, `domain` (253, unique, normalised lowercase), `type` (`subdomain|custom`), `is_primary`,
`status` (`pending|active|disabled`), `verified_at`, `ssl_status` (`pending|active|failed`).
Partial unique `domains_one_primary_per_tenant (tenant_id) WHERE is_primary`.

## Audit (Phase 1, `2026_09_27_150500`)

`audit_logs`: `tenant_id` (nullable, null on delete), `user_id` (nullable), `action`, `subject_type/subject_id`,
`metadata` jsonb, `ip_address`, `user_agent`, `created_at`. Append-only (no `updated_at`).
Current actions: `admin.login`, `admin.login_failed`, `admin.logout`, `tenant.suspended`, `tenant.activated`,
`tenant.created` (onboarding; metadata `source`, `business_type`, `business_type_version`, `website_template`).

## Website (Phase 2, `2026_09_27_160100`) — ADR-012

| Table | Key columns |
|---|---|
| `website_templates` | Platform catalogue: `code` unique, `name`, `description`, `status`, `configuration` jsonb (`theme`: `hero`, `font`, `radius`), `sort_order` |
| `website_configs` | `tenant_id` unique (cascade), `website_template_id` (restrict, indexed), `theme` jsonb (`primary_color`), `seo` jsonb (`title`, `description`), `status` (`published\|draft`), `published_at` — tenant-scoped model |
| `website_sections` | `tenant_id` (cascade), `type` (40), `sort_order`, `enabled`, `configuration` jsonb; index (`tenant_id`, `sort_order`) — tenant-scoped model |

Tenant settings written at creation: `branding` (`business_name`, `primary_color`, `tagline`, `logo_path`),
`business_profile` (`phone`, `email`, `city`, `address`, `description`), plus business-type configuration
keys except `icon`, `website_templates`, `website_sections`.

## CRM (Phase 3, `2026_09_28_100000`) — ADR-013

All tables are tenant-owned (`tenant_id` cascade on tenant delete, `BelongsToTenant` models) and carry a
unique (`id`, `tenant_id`) as the target of composite FKs.

| Table | Key columns |
|---|---|
| `customers` | `name` (150), `phone` (raw), `phone_normalized` (`+<digits>`), `email`, `city`, `address`, `tags` jsonb (string array), `notes`, `created_by_user_id` (null on delete), timestamps, soft deletes; partial unique `customers_tenant_phone_unique (tenant_id, phone_normalized) WHERE deleted_at IS NULL AND phone_normalized IS NOT NULL` |
| `lead_stages` | `code` (unique per tenant), `name`, `color` (hex), `outcome` (`open\|won\|lost`), `sort_order`, `is_active` |
| `lead_sources` | `code` (unique per tenant), `name`, `sort_order`, `is_active` |
| `leads` | `lead_stage_id`, `lead_source_id`, `customer_id`, `assigned_tenant_user_id` — each a composite FK `(x, tenant_id)`; `name`, `phone`, `phone_normalized`, `email`, `interest`, `estimated_value` numeric(12,2), `next_followup_at`, `last_contacted_at`, `converted_at`, `lost_at`, `lost_reason`, `created_by_user_id`, timestamps, soft deletes |
| `activities` | `lead_id`, `customer_id` (composite FKs, cascade), `user_id` (null on delete), `type` (40), `body`, `metadata` jsonb, `occurred_at` |

Activity types — manual: `note`, `call`, `whatsapp`, `email`, `meeting` (`config/crm.php`). System:
`created`, `updated`, `stage_changed`, `assigned`, `converted`, `lost`, `reactivated`.

Tenant setting `crm.auto_assign` (bool) turns on automatic lead assignment.

New audit actions:

- `lead.deleted`, `customer.deleted`;
- `leads.bulk_assign`, `leads.bulk_stage`, `leads.bulk_delete`;
- `crm.stages_updated`, `crm.sources_updated`, `crm.assignment_updated`.

## Services and booking (Phase 4, `2026_09_28_200000`) — ADR-014

All tables are tenant-owned, like the CRM tables. Every cross-row reference is a composite FK
`(x_id, tenant_id)`.

| Table | Key columns |
|---|---|
| `service_categories` | `name` (80, unique per tenant), `sort_order` |
| `services` | `service_category_id` (nullable), `name` (120), `description`, `duration_minutes`, `price` numeric(12,2), `is_active`, `sort_order`, `created_by_user_id`, timestamps, soft deletes |
| `booking_resources` | `tenant_user_id` (nullable; the linked team member), `name` (120), `description`, `color` (hex), `is_active`, `sort_order`, timestamps, soft deletes. Partial unique `booking_resources_member_unique (tenant_id, tenant_user_id) WHERE deleted_at IS NULL AND tenant_user_id IS NOT NULL` |
| `booking_resource_service` | PK (`booking_resource_id`, `service_id`), `tenant_id`; both FKs composite and cascading |
| `resource_working_hours` | `booking_resource_id` (cascade), `weekday` (ISO 1–7), `starts_at` / `ends_at` (`time`, tenant-local). Check `resource_working_hours_valid`: weekday 1–7 and end > start. No timestamps |
| `resource_time_off` | `booking_resource_id` (cascade), `starts_at` / `ends_at` (UTC), `reason` (150), `created_by_user_id`. Check `resource_time_off_valid`: end > start |
| `appointments` | `customer_id`, `booking_resource_id`, `service_id` (nullable); `starts_at` / `ends_at` (UTC); `status` (`pending\|confirmed\|completed\|cancelled\|no_show`); `price` numeric(12,2); `notes`; `source` (`manual`); `confirmed_at`, `completed_at`, `cancelled_at`, `cancellation_reason`, `no_show_at`; `created_by_user_id`; timestamps. Check `appointments_valid`: end > start and a known status. Exclusion constraint `appointments_no_overlap` (below) |

`appointments` has no soft deletes: appointments are cancelled, never deleted.

The constraint `appointments_no_overlap` stops a resource holding two overlapping appointments that occupy
time:

```sql
EXCLUDE USING gist (
    int8range(booking_resource_id, booking_resource_id, '[]') WITH &&,
    tsrange(starts_at, ends_at, '[)') WITH &&
) WHERE (status IN ('pending', 'confirmed', 'completed'))
```

It needs no extension (no `btree_gist`) and works on PostgreSQL 11.

Other changes:

- `activities.appointment_id`: nullable, composite FK, cascade. New activity types: `appointment_booked`,
  `appointment_rescheduled`, `appointment_confirmed`, `appointment_completed`, `appointment_cancelled`,
  `appointment_no_show`, `appointment_updated`.
- New tenant settings:
  - `booking`: `slot_interval`, `auto_confirm`, `default_hours`. Seeded from the business type's
    `configuration.booking` if present (turf); otherwise `config/booking.php` defaults apply.
  - `booking_resource_label`: the word for a resource.
  - `rbac_backfilled_groups`: permission groups already backfilled.

  `service_categories` in the business type configuration is catalogue-only and is not copied.
- New audit actions:
  - `service.deleted`, `service_category.deleted`, `services.bulk_*`;
  - `booking_resource.deleted`, `booking_resource.time_off_added`, `booking_resource.time_off_removed`;
  - `booking.settings_updated`, `appointments.bulk_*`.

## Automation and outbound messages (Phase 5, `2026_09_29_100000`) — ADR-015

All tables are tenant-owned. Cross-row references are composite FKs `(x_id, tenant_id)` and cascade on
delete.

| Table | Key columns |
|---|---|
| `automations` | `name` (120), `description` (500), `trigger` (60, a `config/automation.php` key), `is_active` (default false), `once_per_subject`, `template_key` (60, nullable), `created_by_user_id`, timestamps, soft deletes. Unique (`tenant_id`, `template_key`) includes deleted rows, so a deleted default is never re-created |
| `automation_nodes` | `automation_id`, `position` (unique per automation), `type` (`condition\|wait\|action`), `action` (40, nullable), `config` jsonb |
| `automation_runs` | `automation_id`, `trigger`, `subject_type` (`lead\|customer\|appointment`), `subject_id`, `dedupe_key` (191), `depth`, `status` (`pending\|running\|waiting\|completed\|skipped\|failed\|cancelled`), `steps` jsonb (snapshot of the nodes at start), `payload` jsonb, `attempts`, `error`, `started_at`, `completed_at`. Unique (`tenant_id`, `automation_id`, `dedupe_key`) |
| `automation_jobs` | `automation_run_id`, `step_index` (unique per run), `run_at` (UTC), `status` (`pending\|queued\|running\|completed\|skipped\|failed\|cancelled`), `attempts`, `anchor` (`appointment_start` or null), `offset_minutes`, `queued_at`, `started_at`, `finished_at`, `error` |
| `automation_logs` | `automation_run_id`, `step_index` (nullable), `level` (`info\|warning\|error`), `event` (40, e.g. `run.created`, `condition.failed`, `action.completed`, `message.sent`), `message` (500), `context` jsonb, `created_at` only (append-only) |
| `outbound_messages` | `channel` (`whatsapp\|email`), `provider`, `simulated`, `recipient` (191), `recipient_name`, `subject`, `body`, `status` (`queued\|sending\|sent\|failed`), `idempotency_key` (unique per tenant), `lead_id`, `customer_id`, `tenant_user_id`, `automation_run_id` (all nullable), `attempts`, `provider_message_id`, `error`, `queued_at`, `sent_at`, `failed_at` |

Other changes:

- New activity type `task` (automation "create follow-up task"; it also sets `leads.next_followup_at`).
  Activities written by automations carry `metadata.via = automation`, `automation_id` and
  `automation_run_id`. Message activities also carry `message_id` and `simulated`.
- New audit actions:
  - `automation.created`, `automation.updated`, `automation.activated`, `automation.deactivated`,
    `automation.deleted`;
  - `automation.run_retried`, `automation.run_cancelled`, `automation.message_retried`.
- New business type configuration key `automation_templates` (a list of template keys). It is
  catalogue-only and not copied to the tenant.

## Website builder and media (Phase 6, `2026_09_30_100000`) — ADR-016

| Table | Key columns |
|---|---|
| `media` | `tenant_id` (cascade), `collection` (30: `logo\|hero\|gallery`, from `config('website.media.collections')`), `disk` (30), `path` (255, unique; `tenant/{tenant_id}/{logo\|website}/{collection}-{ulid}.{ext}`), `original_name` (display only), `mime_type` (60, sniffed), `size_bytes`, `width`, `height`, `alt` (150, nullable), `sort_order`, `uploaded_by_user_id` (nullable, null on delete), timestamps. Tenant-scoped model |
| `website_sections` | New unique index (`tenant_id`, `type`): one section per type per tenant |

Other changes:

- **`website_sections.configuration`** holds only the fields defined for the section type in
  `config/website.php` (validated by `SectionSchema`). For example: hero `headline`, `subheadline`, `cta`;
  list sections `items`; contact `show_form`, `form_heading`, `success_message`, `show_map`.
- **`business_profile` setting** adds `whatsapp`, `opening_hours` and `social` (`instagram`, `facebook`,
  `youtube`, `google` URLs). **`branding`** is edited from the website editor. The logo now lives in `media`
  (collection `logo`), so `branding.logo_path` is unused (AW-040).
- **`booking_settings` setting** adds `online`: `enabled`, `auto_confirm`, `min_notice_minutes`,
  `max_days_ahead`, `allow_any_resource` (defaults in `config('booking.online')`).
- **`appointments.source`**: the value `website` is now written for online bookings (labels in
  `config('booking.sources')`).
- **New activity type** `website_enquiry` (lead timeline; `body` = message, `metadata.interest`, plus `name`
  and `email` when added to an existing lead).
  - Customer `created` activities carry `metadata.via = online_booking` for online bookings.
  - `appointment_booked` activities carry `metadata.source` when the source isn't `manual`.
- **New audit actions:**
  - `website.published`, `website.unpublished`, `website.design_updated`, `website.details_updated`;
  - `website.section_updated`, `website.section_added`, `website.section_removed`, `website.section_shown`,
    `website.section_hidden`, `website.sections_reordered`;
  - `media.uploaded`, `media.deleted`;
  - `booking.online_settings_updated`.

## Commerce (Phase 7, `2026_10_01_100000`) — ADR-017

All tables are tenant-owned. Cross-row references are composite FKs `(x_id, tenant_id)`, except
`products.image_media_id` (see below). Money is `numeric(12,2)`.

| Table | Key columns |
|---|---|
| `product_categories` | `name` (80, unique per tenant), `sort_order`, timestamps |
| `products` | `product_category_id` (nullable), `name` (120), `description`, `sku` (60, nullable), `price`, `compare_at_price` (nullable), `image_media_id` (nullable), `is_active`, `track_stock`, `stock_quantity` (int), `low_stock_threshold` (nullable), `sort_order`, `created_by_user_id`, timestamps, soft deletes. Check `products_valid`: prices ≥ 0 and `stock_quantity >= 0`. Partial unique `products_sku_unique (tenant_id, lower(sku)) WHERE deleted_at IS NULL AND sku IS NOT NULL` |
| `orders` | `number` (unique per tenant, from 1001), `customer_id`, `status` (`pending\|confirmed\|ready\|completed\|cancelled`), `source` (`manual\|website`), `fulfilment` (`in_store\|pickup\|delivery`), `subtotal`, `discount`, `delivery_fee`, `total`, `amount_paid`, `payment_status` (`unpaid\|partial\|paid`), `delivery_address` (500), `notes`, `confirmed_at`, `ready_at`, `completed_at`, `cancelled_at`, `cancellation_reason`, `created_by_user_id`, timestamps. Check `orders_valid`: known status and payment status, `discount <= subtotal`, `total = subtotal - discount + delivery_fee`, `0 <= amount_paid <= total` |
| `order_items` | `order_id` (cascade), `product_id` (restrict), `product_name` (120) and `sku` (copied at order time), `unit_price`, `quantity`, `line_total`, `stock_deducted`. Check `order_items_valid`: `quantity > 0`, `line_total = unit_price * quantity` |
| `order_payments` | `order_id` (cascade), `amount` (check `> 0`), `method` (20, a `config('commerce.payment_methods')` key), `reference` (100), `paid_at`, `recorded_by_user_id`, timestamps |
| `stock_movements` | `product_id` (cascade), `quantity_change` (≠ 0), `balance_after` (≥ 0), `reason` (20, a `config('commerce.stock_reasons')` key), `order_id` (nullable), `note` (255), `created_by_user_id`, `created_at` only (append-only) |

`orders` and `order_items` have no soft deletes: orders are cancelled, never deleted. Products are soft
deleted and keep their order items (the FK has no cascade).

`products.image_media_id` references `media.id` alone with `ON DELETE SET NULL`. PostgreSQL 11 cannot null
one column of a composite key, so the application links only images of the same tenant (`ManageMedia` in
the tenant context).

Other changes:

- `activities.order_id`: nullable, composite FK, cascade. New activity types: `order_placed`,
  `order_confirmed`, `order_ready`, `order_completed`, `order_cancelled`, `payment_recorded`,
  `payment_removed`. Customers created by an order carry `metadata.via = order` or `online_order`.
- `media.collection` gains `product` (path `tenant/{tenant_id}/products/`; not shown in the website media
  manager).
- `automation_runs.subject_type` gains `order`.
- New tenant setting `commerce`: `online` (`enabled`, `auto_confirm`, `pickup`, `delivery`, `delivery_fee`,
  `free_delivery_over`, `min_order`, `delivery_note`). Seeded from the business type's
  `configuration.commerce` if present (local store); otherwise `config('commerce.online')` applies.
- New audit actions:
  - `product.created`, `product.deleted`, `product.stock_adjusted`, `product_category.deleted`,
    `products.bulk_*`;
  - `order.payment_recorded`, `order.payment_removed`;
  - `commerce.settings_updated`.

## Messaging (Phase 8, `2026_10_02_100000`) — ADR-018

All tables are tenant-owned with unique (`id`, `tenant_id`) and composite FKs `(x_id, tenant_id)`.

| Table | Key columns |
|---|---|
| `messaging_channels` | `channel` (`whatsapp\|instagram`), `status` (`connected\|disconnected`), `external_id` (64; phone number id or Instagram account id), `business_account_id` (64; WABA id), `display_name`, `display_handle`, `credentials` (text, `encrypted:array`: `access_token`, `app_secret`, `verify_token`), `webhook_key` (64, globally unique), `last_error`, `connected_by_user_id` (null on delete), `connected_at`, `last_webhook_at`. Unique (`tenant_id`, `channel`); unique (`channel`, `external_id`) so a number feeds one tenant only |
| `conversations` | `channel`, `contact_handle` (191; `+<digits>` for WhatsApp, Instagram-scoped id for Instagram), `contact_name`, `customer_id`, `lead_id` (composite, cascade), `assigned_tenant_user_id` (composite, no action — memberships are suspended, never deleted), `status` (`open\|closed`), `unread_count`, `last_message_at`, `last_message_preview` (200), `last_message_direction` (`inbound\|outbound`), `last_inbound_at` (24-hour window), `opted_out_at`. Unique (`tenant_id`, `channel`, `contact_handle`) |
| `conversation_messages` | `conversation_id` (cascade), `channel`, `direction`, `type` (`text`, `template`, `image`, …), `body`, `provider_message_id` (191), `outbound_message_id` (composite, cascade), `meta` jsonb (`template`, `reply_to`), `sent_at`, `created_at` only. Partial unique `conversation_messages_provider_unique (tenant_id, channel, provider_message_id) WHERE provider_message_id IS NOT NULL` |
| `message_templates` | `channel`, `name` (512), `language` (20), `category`, `status` (Meta's, e.g. `APPROVED`), `body` (BODY text), `variables` (highest `{{n}}`), `provider_template_id`, `synced_at`. Unique (`tenant_id`, `channel`, `name`, `language`) |
| `messaging_webhook_calls` | `messaging_channel_id` (cascade), `payload` jsonb, `status` (`pending\|processed\|failed`), `attempts`, `error`, `processed_at`, timestamps. Pruned after `messaging.webhooks.retention_days` |

`outbound_messages` gains:

- `conversation_id` (composite, cascade), `sent_by_user_id` (null on delete);
- `template` jsonb (`name`, `language`, `params`), `scheduled_for` (quiet hours), `delivered_at`, `read_at`;
- `status` values `delivered` and `read`; `channel` value `instagram`;
- unique (`id`, `tenant_id`) and index (`tenant_id`, `provider_message_id`).

Other changes:

- Activity types `whatsapp` and new `instagram` are written for inbound messages with
  `metadata.direction = inbound`, `conversation_id`, `from_name` and `opt_out` (`out|in`). Outbound message
  activities add `direction = outbound`, `via` (`automation|inbox|system`), `conversation_id` and
  `template`. Leads created from a message use the source `whatsapp` or `instagram`.
- Automation `send_whatsapp` config may be `{mode: template, template: {name, language}, params: [...]}`.
- New tenant setting `messaging`: `quiet_hours` (`enabled`, `start`, `end`), `email` (`from_name`,
  `reply_to`).
- New permission group `conversations` (`view`, `reply`, `assign`).
- New audit actions: `messaging.channel_connected`, `messaging.channel_disconnected`,
  `messaging.templates_synced`, `messaging.settings_updated`, `conversation.opted_out`,
  `conversation.opted_in`.

## AI (Phase 9, `2026_10_03_100000`) — ADR-019

Both tables are tenant-owned (`BelongsToTenant`, `tenant_id` cascade on tenant delete).

| Table | Key columns |
|---|---|
| `ai_usage` | One row per provider call: `user_id` (null on delete), `feature` (`reply\|summary\|extraction\|assistant\|copy`), `provider`, `model` (100), `status` (`succeeded\|failed`), `prompt_tokens`, `completion_tokens`, `total_tokens`, `cost` decimal(12,6) USD (null when not reported), `duration_ms`, `error` (500), `created_at` only. No prompts or answers are stored |
| `ai_results` | `feature` (`summary\|reply_draft\|extraction`), `subject_type` (`conversation\|lead\|customer`), `subject_id`, `key` (191; the input state, e.g. `conversation:12:m345`, or an automation idempotency key), `status` (`ready\|used\|dismissed`), `output` jsonb (`text` for summaries and drafts; `filled`, `suggestions` {attribute: {value, current, status}}, `preferred_time`, `summary`, `via` (`manual\|auto\|automation`), `used_ai` for extraction), `created_by_user_id` (null on delete), timestamps. Unique (`id`, `tenant_id`), unique (`tenant_id`, `feature`, `key`) |

Other changes:

- Catalogue module `ai` (no dependencies), offered to every business type.
- Permission group `ai` (`use`, `assistant`).
- Tenant settings `ai` (`enabled`, `auto_extract`, `tone`, `notes`), `ai_quota` (`monthly_tokens`; written
  only by platform admins) and `modules_backfilled` (module codes already switched on for the tenant by
  `TenantBackfillSeeder`, so a module the owner turns off stays off).
- Automation run subject `conversation`; trigger `message.received`; actions `ai_extract_lead`,
  `ai_draft_reply`, `ai_summarize`.
- Activity metadata: `updated` entries may carry `via` `ai` (filled by extraction) or `ai_suggestion`
  (applied by staff); `note` entries from `ai_summarize` carry `via = ai`, `automation_id`,
  `automation_name` and `idempotency_key`.
- New audit actions: `ai.settings_updated`, `ai.limit_updated`.

## Additional verticals (Phase 10, `2026_10_04_100000`–`100300`) — ADR-020

All new tables are tenant-owned (`BelongsToTenant`, `tenant_id` cascade on tenant delete), have unique
(`id`, `tenant_id`) where referenced, and reference their parents with composite FKs.

**Booking prices and payments (`2026_10_04_100000`)**

| Table / column | Key columns |
|---|---|
| `booking_resources.hourly_rate` | decimal(12,2) null, CHECK ≥ 0 |
| `booking_resources.rates` | jsonb null: list of `{label, weekdays[1-7], from, to (HH:MM, to ≤ 24:00), hourly_rate}` |
| `appointments.amount_paid` | decimal(12,2) default 0, CHECK `0 ≤ amount_paid ≤ price` |
| `appointment_payments` | `appointment_id` (composite FK, cascade), `amount` (> 0), `method` (20), `reference` (100), `paid_at`, `recorded_by_user_id` (null on delete) |

**Education (`2026_10_04_100100`)**

| Table | Key columns |
|---|---|
| `courses` | `name` (120, unique lower(name) per tenant among live rows), `description`, `fee` (≥ 0, null), `duration_label` (60), `is_active`, `sort_order`, soft deletes |
| `batches` | `course_id`, `name`, `starts_on`/`ends_on`, `weekdays` jsonb, `start_time`/`end_time`, `capacity` (> 0, null), `teacher_tenant_user_id` (composite FK to `tenant_users`), `room` (60), `fee` (null = course fee), `is_active`, soft deletes; CHECK dates and times ordered |
| `enrolments` | `customer_id`, `batch_id`, `lead_id` (null), `status` (`active\|completed\|dropped`), `enrolled_on`, `fee_total`, `discount` (≤ fee), `amount_paid` (≤ fee − discount), `notes`, `completed_at`, `dropped_at`, `created_by_user_id`. Partial unique (`tenant_id`, `batch_id`, `customer_id`) WHERE active |
| `fee_instalments` | `enrolment_id` (cascade), `sequence` (unique per enrolment), `due_on`, `amount` (> 0), `amount_paid` (≤ amount), `reminded_at`, `overdue_notified_at` |
| `fee_payments` | `enrolment_id` (cascade), `amount` (> 0), `method`, `reference`, `paid_at`, `recorded_by_user_id` |
| `class_sessions` | `batch_id` (cascade), `held_on` (unique per batch), `topic` (150), `created_by_user_id` |
| `attendance_records` | `class_session_id`, `enrolment_id` (both cascade; unique pair), `status` (`present\|absent\|late\|excused`) |
| `demo_classes` | `lead_id` (cascade), `course_id`, `batch_id` (null), `scheduled_at`, `status` (`scheduled\|attended\|no_show\|cancelled`), `notes`, `created_by_user_id` |

**Food (`2026_10_04_100200`)**

| Table / column | Key columns |
|---|---|
| `products.food_type` | `veg\|non_veg\|egg` or null |
| `products.is_available` | boolean default true ("sold out today") |
| `dining_tables` | `name` (40, unique lower(name) per tenant among live rows), `seats` (1–100), `area` (40), `is_active`, `sort_order`, soft deletes |
| `reservations` | `customer_id`, `dining_table_id` (null), `party_size` (1–100), `reserved_at`, `ends_at` (> reserved_at), `status` (`pending\|confirmed\|seated\|completed\|cancelled\|no_show`), `source` (`manual\|website`), `notes`, `cancellation_reason`, status timestamps, `created_by_user_id`. Exclusion constraint `reservations_no_overlap` (table × time range, live statuses) |
| `orders.dining_table_id` | composite FK, null; CHECK only with `fulfilment = dine_in` |
| `orders.customer_id` | now nullable; CHECK required unless `fulfilment = dine_in` |
| `order_items.notes` / `kitchen_status` / `added_at` | `kitchen_status` `queued\|ready` or null; partial index on queued items |

**Coupons (`2026_10_04_100300`)**

| Table / column | Key columns |
|---|---|
| `coupons` | `code` (30, unique lower(code) per tenant among live rows), `description` (150), `type` (`percent\|fixed`), `value` (> 0, ≤ 100 for percent), `min_subtotal`, `max_discount`, `starts_at`/`ends_at`, `usage_limit`, `times_used`, `online`, `is_active`, soft deletes |
| `orders.coupon_id` / `coupon_code` | composite FK (null) and the code as typed |

Other changes:

- Engines `education` and `food`; the catalogue module `offers` is offered to Cafe & Restaurant and Local
  Commerce and backfilled once.
- Permission groups `courses`, `students`, `fees`, `reservations`.
- Tenant settings `education` (`default_instalments`, `reminder_days_before`) and `food`
  (`reservations`: `online`, `auto_confirm`, `duration_minutes`, `opens`, `closes`, `slot_interval`,
  `max_party_size`, `min_notice_minutes`, `max_days_ahead`).
- Automation subjects `enrolment`, `fee`, `demo_class`, `reservation`.

## Platform settings (`2026_10_05_100000`) — not tenant-owned

| Table | Key columns |
|---|---|
| `platform_settings` | `key` unique, `value` json. Keys: `require_email_verification` (bool). Missing keys fall back to `config('autowave.platform_settings')`; read through `PlatformSettings` (cached one hour, cleared on change) |

## Deferred platform tables

`feature_flags`, `custom_fields` — added with the first feature that needs them (AW-009).

## Planned domain tables (Phase 11 onwards)

See `docs/01-product/master-prompt.md` §57. Implement only what the current phase needs.
