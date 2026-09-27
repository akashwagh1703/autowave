# RBAC

- **Status:** ✅ Phase 1 foundation (role management UI later)
- **Last updated:** 2026-09-27

## Purpose

Control what each team member can do inside each business.

## Rules

- Permissions are a platform catalogue (`config/rbac.php`); roles are per tenant, copied from templates on
  tenant creation. Tenants may later customise their copies without affecting templates.
- A user's permissions are the union of their roles' permissions in the **current** tenant only.
- Owner (`grants_all`) always has every permission, including new ones.
- Nothing is granted when the user, membership or tenant is suspended.

## Database

`permissions`, `roles`, `role_permissions`, `user_roles` (composite FKs keep assignments inside one tenant).

## Usage

```php
$user->can('leads.assign');                       // Gate, current tenant
Route::get(...)->middleware('can:settings.view');
app(AssignRole::class)->handle($membership, $role);
```

React receives `permissions` (array of keys) for UI visibility only.

## Testing

`tests/Feature/Rbac/PermissionTest.php`.

## Future extensions

Role editor UI, invitations with role selection, per-resource policies (Phase 3+), platform admin roles.
