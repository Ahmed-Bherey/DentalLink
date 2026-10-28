# Identity and Role Model

Introduced in Stage 3. This is the reference for how DentHub answers three
separate questions that used to be conflated into one column.

## The three concepts

| Question | Answered by | Authoritative? |
| --- | --- | --- |
| What kind of access does this user have? | `users.role_id` → `roles.code` | **Yes** |
| Which organisational unit do they belong to? | `users.department_id` → `departments` | Legacy, non-authoritative |
| Which records belong to them? | `products.user_id`, `orders.doctor_id`, `payments.supplier_id`, … | Yes, per table |

Before Stage 3 the first two were the same column. Every access decision in the
application read `$user->department->code`, and the only two department rows
were literally `doctor` and `supplier`. `DepartmentResource` even exposed
`code` under the JSON key `role`.

## Roles

`roles` is system reference data. Rows are created by the
`create_roles_table` migration and by `RoleSeeder`; there is **no** HTTP
endpoint that creates, updates or deletes a role.

| code | self-assignable | meaning |
| --- | --- | --- |
| `super_admin` | no | Full administrative identity |
| `doctor` | yes | Clinic-side buyer |
| `supplier` | yes | Vendor / selling side |

Codes are spelled in exactly one place: `App\Support\RoleCode`.

`roles.assignable_at_registration` is the identity-level guard. A self-registering
user may only claim a role flagged `true`. The flag lives in the database, so no
request payload can influence it.

### Adding a role later

1. Add it to `RoleCode::definitions()`.
2. Add a migration that inserts the row (do not edit the original migration).
3. Run `php artisan db:seed --class=RoleSeeder`.

Do not write role-code literals into controllers or services. Use
`RoleCode::*`, `$user->isDoctor()` / `isSupplier()` / `isSuperAdmin()` /
`hasRole(...)`, or the `User::withRole(...)` query scope.

## Super Admin

Super Admin is a role code, nothing else. It does not depend on a department
name, a department id, or any user-supplied value. Creating a department called
`super_admin` grants nothing — there is a test for exactly that.

**Assignment is console-only:**

```bash
php artisan identity:assign-role user@example.test super_admin
```

This is a deliberate temporary boundary. There is no admin API for role
assignment because building one before the Stage 4 policy layer exists would
recreate the privilege-escalation primitive Stage 1 removed. Requiring shell
access is the minimum sufficient control until then.

What a Super Admin is *permitted to do* is defined in Stage 4. Stage 3 only
establishes that the identity exists and cannot be self-claimed.

## Users without a role

`users.role_id` is **nullable on purpose**.

The Stage 3 backfill mapped `users.department_id → departments.code → roles.code`
by exact match only. Any user it could not resolve was left with `role_id = NULL`
rather than being given a guessed access level.

A null role means **no access**. It is never treated as a wildcard.

```bash
php artisan identity:audit-roles      # lists unresolved users, grouped, no PII
php artisan identity:assign-role 42 doctor
```

Making `role_id` NOT NULL is deliberately deferred until `identity:audit-roles`
exits zero on the target environment.

## Legacy compatibility

These are retained on purpose and are **scheduled for removal**, not permanent:

| Item | Why retained | Remove when |
| --- | --- | --- |
| `users.department_id` column | Frontend reads it | Frontend stops reading it |
| `department` relation on `User` | Backs the deprecated response fields | Same |
| `GET /api/department/index` | Legacy registration dropdown | Frontend uses `GET /api/role/index` |
| `department_id` accepted by `POST /api/register` | Live registration form sends it | Frontend sends `role_id` |
| `department_id` / `department` in profile + login responses | Frontend may read them | Frontend reads `role` / `role_details` |
| `config('auth.self_assignable_department_codes')` | Backs the legacy department filter | With `department/index` |

The legacy config key is **derived from `RoleCode::selfAssignable()`**, so it can
never drift from the role definitions.

`departments` is **not** seeded by any migration — it is pre-existing production
data. A fresh install has an empty department list, which is expected; new
frontends use `role/index`.

## API surface

### New

| Method | Path | Auth | Notes |
| --- | --- | --- | --- |
| `GET` | `/api/role/index` | public | Returns **only** self-assignable roles |
| `GET` | `/api/password/policy` | public | Minimum length + description |
| `POST` | `/api/password/forgot` | public | Rate limited; identical response for unknown addresses |
| `POST` | `/api/password/reset` | public | Broker token |
| `POST` | `/api/password/change` | sanctum | Revokes all tokens |
| `POST` | `/api/logout` | sanctum | Revokes the current token |
| `POST` | `/api/logout-all` | sanctum | Revokes every token |

### Changed (additive — no field removed)

`POST /api/login` → adds `data.department_id`. `data.role` now comes from the
role table; the values are unchanged (`doctor`, `supplier`).

`GET /api/profile/show` → `data.role` now comes from the role table (same
values). Adds `data.role_details` (`{id, code, name}`), `data.department_id`,
`data.department` (`{id, name}` or null).

`POST /api/register` → accepts `role_id` (preferred) or `department_id`
(legacy). Exactly one is required.

**Breaking:** the registration password policy is now min 8 characters with a
letter and a number, up from `min:6`. New registrations only; stored passwords
are untouched and existing users are not forced to reset.

`password_confirmation` is **not** required at registration, deliberately — the
live form does not send it. It **is** required for password change and reset.

## Deployment requirements

| Variable | Required | Default | Purpose |
| --- | --- | --- | --- |
| `APP_FRONTEND_RESET_URL` | **Yes, for password reset** | none | Page that collects the new password. Receives `?token=…&email=…` and posts to `/api/password/reset`. Without it the email states the raw token instead of a link — usable but poor UX. |
| `SANCTUM_TOKEN_EXPIRATION` | No | `10080` (7 days) | Token lifetime in minutes |
| `AUTH_PASSWORD_MIN_LENGTH` | No | `8` | Minimum password length |
| `MAIL_*` | **Yes, for password reset** | — | Reset email delivery. Verify before announcing the feature. |

Also required: the scheduler must run, so expired tokens are pruned daily.

```
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

## Upgrade order

```bash
php artisan migrate          # creates roles, adds users.role_id, backfills
php artisan identity:audit-roles
# resolve anyone listed, then:
php artisan identity:assign-role <admin-user> super_admin
```

The three Stage 3 migrations are additive and reversible. `down()` on the
backfill nulls `role_id` only — it never touches `department_id`, so the legacy
source of truth survives a rollback intact.
