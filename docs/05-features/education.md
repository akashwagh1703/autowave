# Education: courses, batches, students, fees and demo classes

- **Status:** ✅ Phase 10
- **Decision:** [ADR-020](../12-decisions/ADR-020-additional-verticals.md)
- **Last updated:** 2026-10-04

## Purpose

Run a coaching centre, tuition class or academy: what is taught (courses), when and by whom (batches),
who is enrolled (students), what they owe (fees) and who came to class (attendance). Enquiries arrive
as leads, can attend a demo class and are admitted in one step.

The `education` engine is on for the **Coaching Centre** business type.

## Concepts

| Concept | Meaning |
|---|---|
| Course | A subject or programme, e.g. "Class 10 Maths", with an optional fee and duration label. |
| Batch | A group of a course that meets on set weekdays and times, with an optional teacher (team member), room, capacity, dates and its own fee. |
| Student | A **customer** with an enrolment. There is no separate student record. |
| Enrolment | A student in a batch: status (active, completed, dropped), fee, discount, amount paid, and the lead it came from. |
| Fee instalment | One due date and amount of an enrolment's fee plan. |
| Fee payment | Money received (cash, UPI, card, bank transfer, other); allocated to instalments oldest first. |
| Class session | One class of a batch on a date, created when attendance is first saved. |
| Demo class | A trial class booked for an enquiry (lead). |

## User flow

1. **Courses → Add course.** Then **Add batch**: days, times, teacher, capacity, start and end dates.
   Editing a course also takes an optional **Photo**, shown as a card in the WhatsApp assistant.
2. **Leads → an enquiry → Schedule demo.** The lead moves to "Demo scheduled" (when the tenant has that
   stage). After the class, mark it attended or no-show.
3. **Admit** from the lead card, from **Students → Admit**, or for a new walk-in. Choose the batch,
   check the fee and discount, split it into instalments (equal monthly by default, or typed), and
   optionally record the first payment. An open lead is converted to a customer and moves to the won
   stage ("Admitted").
4. **Students → a student:** record payments, change the fee plan, drop, complete or re-activate.
5. **Batches → a batch → Attendance:** pick a date the batch meets, mark each active student present,
   absent, late or excused, save. Saving again corrects the day.
6. **Fees:** overdue and due-soon instalments across all students.

## Rules

- Capacity is checked under a row lock on the batch; a full batch refuses admissions and re-activation.
- One active enrolment per student and batch.
- Inactive batches, and batches of deleted courses, take no admissions.
- The discount cannot exceed the fee. Typed instalments must add up to fee minus discount exactly.
  Equal splits put any rounding remainder on the last instalment (10,000 in 3 → 3,333.33 / 3,333.33 /
  3,333.34).
- Payments cannot exceed the balance. Removing a payment re-allocates the rest.
- Editing the fee plan keeps reminder history on instalments whose sequence stays.
- Attendance is only for active students of the batch, on dates not in the future.
- A demo needs an open lead (not won or lost), at most `education.max_demo_days_ahead` days ahead, and
  can be marked attended or no-show only after its start time.
- Courses and batches are soft-deleted; a batch with active students cannot be deleted.

## Configuration

`config/education.php` holds the defaults. Tenants override them in **Settings → Coaching**
(tenant setting `education`):

| Setting | Default | Meaning |
|---|---|---|
| `default_instalments` | 1 | Instalments offered on the admission form. |
| `reminder_days_before` | 3 | `fee.due_soon` fires this many days before a due date (0 = on the day). |

## Fee reminders

`php artisan education:fee-reminders` runs **hourly** (scheduler, one server, no overlap). For each
tenant with the education engine, each unpaid instalment of an active enrolment:

- fires `fee.due_soon` once, when its due date is within the reminder window (`reminded_at`);
- fires `fee.overdue` once, the day after its due date (`overdue_notified_at`).

Timestamps are claimed with a conditional update, so overlapping runs never fire twice. Dropped and
completed students get no reminders.

## Database

`courses`, `batches`, `enrolments`, `fee_instalments`, `fee_payments`, `class_sessions`,
`attendance_records`, `demo_classes`. All tenant-scoped with composite foreign keys to their parents
(`(id, tenant_id)`). See [schema.md](../03-database/schema.md).

## Routes and permissions

| Route | Permission |
|---|---|
| `GET /courses` | `courses.view` |
| `POST/PUT/DELETE /courses…` (including `/courses/{course}/image`), `/batches/create`, `POST/PUT/DELETE /batches…` | `courses.manage` |
| `GET /batches/{batch}` | `courses.view` |
| `POST /batches/{batch}/attendance` | `students.attendance` |
| `GET /students`, `GET /students/{enrolment}` | `students.view` |
| `GET /students/admit`, `POST /students`, `GET /students/lookup` | `students.admit` |
| `PUT /students/{enrolment}`, `PATCH …/status` | `students.update` |
| `PUT /students/{enrolment}/fees` | `fees.manage` |
| `POST/DELETE /students/{enrolment}/payments…` | `fees.collect` |
| `GET /fees` | `fees.view` |
| `GET /demos` | `students.view` |
| `POST /leads/{lead}/demos`, `PATCH /demos/{demo}` | `students.admit` (+ `leads` module for booking) |
| `GET/PUT /settings/education` | `settings.view` / `settings.update` |

All routes need the `education` engine (404 otherwise). Template roles: Manager gets all; Receptionist
views, admits and collects fees; Sales Executive views and admits; Staff views and takes attendance;
Accountant views courses, students and fees.

## Events (automation triggers)

| Trigger | Subject | Default template |
|---|---|---|
| `enrolment.created` | enrolment | "Welcome new students on WhatsApp" (paused) |
| `fee.due_soon` | fee | "Fee due reminder" (paused) |
| `fee.overdue` | fee | "Follow up on overdue fees" — adds a task (active) |
| `demo.scheduled` | demo class | "Confirm demo classes on WhatsApp" (paused) |

Variables: `{{enrolment.course}}`, `{{enrolment.batch}}`, `{{enrolment.schedule}}`,
`{{enrolment.balance}}`, `{{fee.amount_due}}`, `{{fee.due_date}}`, `{{demo_class.date}}`,
`{{demo_class.time}}`, `{{demo_class.course}}`. Condition fields: admission status, course, fee
balance, instalment amount due.

## Timeline and audit

Admission (`admitted`), payments (`fee_paid`, `fee_payment_removed`) and demo classes
(`demo_scheduled`) are written to the customer / lead timeline, and to the audit log.

## Dashboard widgets

`students` (active students), `admissions` (last 30 days), `fees_due` (overdue amount, needs
`fees.view`), `demo_classes` (upcoming). The assistant has a `students` tool.

## Website

The `courses` section lists active courses with fees (optional), duration, and batches with schedule
and start date (optional), with an "Enquire / book a demo" button that scrolls to the contact form.
Rooms and teachers are never shown.

## Security

- Every table is tenant-scoped (fail-closed) with composite foreign keys; cross-tenant ids return 404
  or a validation error.
- Money is decimal and computed with bcmath; amounts come from the server.
- Staff cannot see fees or admit students by default.

## Testing

`tests/Feature/Education/EducationTest.php`, `FeeRemindersTest.php`, `EducationIsolationTest.php`,
plus `tests/Feature/Tenancy/VerticalProvisioningTest.php` and
`tests/Feature/AI/VerticalAssistantToolsTest.php`.

## Known limitations

- No online payment or receipts (manual payments only).
- No timetable clash check between batches or teachers.
- Reminders fire in the first hourly run after the window opens (AW-064).
- Older coaching websites do not get the `courses` section automatically (AW-059).
