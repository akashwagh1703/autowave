# Feature Documentation

One file per major feature, created **when the feature is implemented** (or when its specification is approved).
Do not document planned features as if they exist.

## Index

| Feature | Document | Status |
|---|---|---|
| Onboarding | [onboarding.md](onboarding.md) | ✅ Phase 2 |
| Tenant management | [tenant-management.md](tenant-management.md) | ✅ Phase 1 (foundation) |
| RBAC | [rbac.md](rbac.md) | ✅ Phase 1 (foundation) |
| Lead management | [lead-management.md](lead-management.md) | ✅ Phase 3 |
| Customers | [customers.md](customers.md) | ✅ Phase 3 |
| Services | [services.md](services.md) | ✅ Phase 4 |
| Booking | [booking.md](booking.md) | ✅ Phase 4 (staff-side); online booking in Phase 6 ([website.md](website.md)); turf rates and advances in Phase 10 |
| Commerce | [commerce.md](commerce.md) | ✅ Phase 7 (products, stock, orders, manual payments, website cart); coupons and dine-in in Phase 10 |
| Education (coaching) | [education.md](education.md) | ✅ Phase 10 |
| Food (cafe & restaurant) | [food.md](food.md) | ✅ Phase 10 |
| Website | [website.md](website.md) | ✅ Phase 6; products section and cart in Phase 7 |
| Automation | [automation.md](automation.md) | ✅ Phase 5 |
| Messaging | [messaging.md](messaging.md) | ✅ Phase 8 (outbound pipeline in Phase 5) |
| AI | [ai.md](ai.md) | ✅ Phase 9 |
| WhatsApp assistant | [whatsapp-assistant.md](whatsapp-assistant.md) | ✅ Steps 1–3 (menu, information, hand-over; booking, reservations, ordering and demo classes in the chat; opt-in AI answers to typed questions; photo cards for products, services and courses) |
| Billing | [billing.md](billing.md) | ✅ Phase A (plans, trial, manual payments, GST switch, enforcement); Razorpay in Phase B |

## Template

```markdown
# <Feature>

- **Status:** ✅ / 🚧 / ⏳
- **Issue(s):** AW-XXX
- **Last updated:** YYYY-MM-DD

## Purpose
## Problem
## Actors
## User Flow
## Rules
## Database
## API
## Permissions
## Events
## Jobs
## UI
## Automation
## Security
## Testing
## Known Limitations
## Future Extensions
```
