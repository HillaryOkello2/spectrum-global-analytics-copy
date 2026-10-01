# Frontend API Guide — Spectrum Global Analytics

Companion to the auto-generated reference. This page is the mental model; the full
per-endpoint reference (request/response schemas, try-it-out, code samples) lives at:

- **Interactive docs:** `GET /docs` (run the app, open `http://127.0.0.1:8000/docs`)
- **OpenAPI spec:** `GET /docs.openapi` → import into any client/codegen
- **Postman collection:** `GET /docs.postman`

Everything is under `/api/v1`. JSON only.

## ✅ Added for analytics-portal (2026-10-01)

Answers to `ANALYTICS_PORTAL_BACKEND_REQUIREMENTS.md`. All additive — nothing you already call
changed shape. Full item-by-item reply in `docs/ANALYTICS_PORTAL_BACKEND_RESPONSE.md`.

- **Component star rating** — `averageRating` + `ratingsCount` on `GET /catalog/components` and the
  vault equivalent. Grid tile and detail masthead are unblocked; don't average products client-side.
- **Reading time** — `wordCount` + `readMinutes` on every product shape, listings included.
- **Vault rows** — `status`, `isHidden` and `readsCount` now sit on each row (that last one is the
  real view count you thought didn't exist).
- **`GET /admin/vault/products/{product}`** — one product in full for staff, any status. Drop the
  task-board fallback: `GET /products/{product}` is subscriber-gated and **always** 403s for staff.
- **Topic author** — `createdBy: { publicId, name }`, resolved server-side. No `manage users` needed.
- **Role description** — `description` on role payloads, settable on create and update.
- **`GET /me/stats`** — the subscriber's own `articlesRead`, `reads`, `ratingsGiven`,
  `averageRatingGiven`.
- **Already there before this pass**, whatever a stale reference says: `byline`, `averageRating` /
  **`ratingsCount`** (plural) and `code` on *all* product shapes including listings; `product.code`
  on the task board list; `joinedAt` on subscriber rows.

## ⚠️ Changed: payments go through PGW (2026-09-14)

- **Paid amounts are now KES** with PGW: `payment.amount`/`currency` is what's charged, and the new
  `listAmount`/`listCurrency` is the USD tier price. `GET /tiers` adds `charge` for the KES figure.
- **`GET /payments/{payment}/status` needs no token** any more (it still works with one), and there
  is a new **`POST /payments/{payment}/retry`**. A paid signup can finally learn its outcome.
- Card payments return **`instructions.checkoutUrl`**, and PGW sends the payer back to a new
  subscriber-portal page, **`/payment/return?payment={publicId}`**, which the portal needs to build.
- Payments gained **`failureReason`**; the `payment_pending` login error now carries `meta.payment`.
- M-Pesa (the default) now requires a **Kenyan mobile**, or `422`. See [Payments](#payments-frontend-flow).

## ⚠️ Breaking: component codes changed (2026-08-25)

The client's finalised prompt pack replaced the placeholder `A1`–`A9` codes with the nine real
product types. Read the **"Breaking changes — 2026-08-25"** table at the top of `API_REFERENCE.md`
before your next pass. The short version:

| Old | New | |
|---|---|---|
| `A4` `A5` `A6` | `DB` `WH` `MF` | the daily / weekly / monthly pulse products |
| `A2` `A3` `A7` | `ES` `BS` `RP` | essays, books, research papers |
| `A8` + `A9` | `WP` | white papers, merged — one client prompt covers both |
| — | `CC` `HM` | new: Close-Circuit Briefs and Crisis Simulations |
| `A1` | *removed* | Abstract Papers — no prompt in the client pack |

- **Product codes changed shape**: `SGA.A4.2026-08.017` → **`SGA.DB.001.08.26`**. Display only —
  routing on it still 404s, as it always has. Use `publicId`.
- Products gained **`byline`**, a one-line subtitle under the title.
- **Star ratings**: `GET`/`PUT`/`DELETE /products/{product}/rating`, plus `averageRating` and
  `ratingsCount` on product payloads. Rating needs read access, not just a preview.
- **Four new admin charts**: subscribers by location, most-read products, product ratings,
  rejections.
- `/proofread` takes **`body` only**. The abstract is derived from it server-side; title and byline are already set.
- `DB`, `WH` and `MF` now **commission their own topics** on a schedule — those topics come back
  with `"source": "auto"`, `promptText: null` and a `variables` object.

## ⚠️ Breaking: Pillars are gone (2026-08)

The client removed the catalogue's top level. **Components are now the entry point and there are nine
of them (A1–A9).** Read the "Breaking changes" table at the top of `API_REFERENCE.md` before your next
integration pass — the short version:

- `/catalog/pillars*`, `/admin/vault/pillars*` and `/admin/analytics/products-by-pillar` are **gone**.
  Use `/catalog/components`, `/admin/vault/components`, `/admin/analytics/products-by-component`.
- No `pillar` object appears on anything any more. `{component}` accepts a code (`DB`) or a `publicId`.
- `firstParagraph` → **`abstract`** (derived from the document at `/proofread`). Products gained a
  human-readable **`code`** like `SGA.DB.001.08.26`, and a third content level, **`redactedBody`**.
- Proofreading is two stages (`/proofread` then `/redact`), with a new `awaiting_redaction` status.
- **Approving no longer publishes** — approved products go live on a scheduled FIFO release.

## Authentication

Token-based (Laravel Sanctum). Flow:

1. `POST /api/v1/auth/register` — sign up. **Freemium** returns `{ data: { user, token } }` and the
   account is active immediately. **Paid tiers** return `{ data: { payment, instructions } }` and the
   account stays pending until payment clears (see Payments).
2. `POST /api/v1/auth/login` — returns `{ data: { user, token, portal } }`. `portal` is `"subscriber"`
   or `"admin"` — use it to decide which app/area to route into.
3. Send the token on every authenticated request: `Authorization: Bearer {token}`.
4. `POST /api/v1/auth/logout` revokes the current token.

Login edge cases (both HTTP 403, distinguish by `code`):
- `payment_pending` — registered but payment not completed; send them back to the payment step.
  `meta.payment` is their latest payment, to poll or retry.
- `account_suspended` — blocked by an admin.

A subscriber token only opens Subscriber endpoints; an admin token only opens Admin endpoints (403 otherwise).

## Response conventions

- **Envelope:** single resources come as `{ "data": { ... } }`; lists add pagination `meta` + `links`.
- **Keys are camelCase** (`publicId`, `abstract`, `publishedAt`).
- **IDs are `publicId` UUIDs.** Use these everywhere in URLs — internal numeric ids are never exposed.
- **Dates** are `Y-m-d H:i:s` strings (or `Y-m-d` for invoice/issue dates).

## Error contract

| HTTP | Shape | When |
|---|---|---|
| 422 | `{ message, errors: { field: [msg] } }` | Validation failure (standard Laravel) |
| 401 | `{ message }` | Missing/invalid token |
| 403 | `{ message, code }` | Wrong role, or a domain rule (see codes below) |
| 404 | `{ message }` | Not found, or content hidden/unpublished/out-of-tier |
| 409 | `{ message, code }` | Invalid workflow transition |

Domain `code` values you should handle in the UI:
- `quota_exhausted` (403) — metered limit hit; response includes `meta: { used, limit, access }`. Show an upgrade prompt.
- `subscription_not_active` (403) — no active subscription.
- `invalid_task_transition` (409) — admin task board action not allowed from the current state.
- `payment_not_retryable` (409) — the payment is pending or paid, or a newer attempt exists.
- `payment_pending` / `account_suspended` (403) — login states above.

## The four areas

### Authentication (public)
`register`, `login`, `logout`, `forgot-password`, `reset-password`. The reset email links to
`{portal origin}/reset-password?token=…&email=…` — staff to the admin portal, subscribers to the
subscriber portal — and that page posts both values, with the new password, to `/auth/reset-password`.

### Public Catalogue (public, no token)
Browse without logging in (FR-08):
- `GET /catalog/components` — the 9 Components, in `sortOrder`, each with `productsCount` and its
  own `averageRating`/`ratingsCount` (absent average = nobody has rated it).
- `GET /catalog/components/{component}/products` — published products (title + excerpt only), paginated.
- `GET /products/{product}/preview` — **abstract + `locked: true`** for any product (the gated
  teaser: render the abstract, a lock, and a Subscribe CTA).
- `GET /tiers` — subscription tier cards with their per-component allocations (for the pricing page).

> Hierarchy is **Component → Product**. `{product}` in URLs is a `publicId`; `{component}` accepts
> either a `publicId` or the component `code` (e.g. `DB`) — codes are globally unique now.
>
> **A product resolves by `publicId` only.** Routing on the product `code` returns 404 — the code is
> for display. This is the single most common integration mistake here.

Components carry **`productsCount`** on these listings — how many products sit under them, so you can
badge the tile before the user opens it. On public/subscriber endpoints it counts published,
non-hidden products; in the admin vault it counts everything. It is absent from `component` objects
nested inside a product payload.

### Subscriber Portal (token, role `subscriber`)
- `GET /dashboard` — active subscription card + recent products.
- `GET /me/stats` — the caller's own `articlesRead`, `reads`, `ratingsGiven`, `averageRatingGiven`.
- `GET /me`, `PATCH /me`, `PUT /me/password` — profile + password. **Open to staff too**, the only
  routes here that are: staff need them to change the temporary password they are emailed.
- `GET /me/subscription` — subscription details incl. tier allocations.
- `GET /me/invoices`, `GET /me/invoices/{invoice}` — billing history.
- `GET /products/{product}` — **entitlement-gated product**, served at one of three content levels.
  This is the key one. Branch on `locked` and `redacted`, not on which fields are present:
  - Full access → `{ data: { ..., abstract, body, locked: false }, meta: { access, used, limit } }`.
  - Withheld **but** an approved redaction exists → `{ data: { ..., abstract, redactedBody,
    locked: true, redacted: true }, meta: { reason: "redacted_access" } }`. Render the redacted text
    with an upgrade CTA above it.
  - Withheld with no redaction → the **abstract shape** (`abstract`, `locked: true`) + `meta.reason`
    (`preview_only` / `denied`). Same locked teaser as the public preview.
  - Metered limit hit and no redaction to fall back to → **403 `quota_exhausted`** with
    `meta.used`/`meta.limit`.
  - The `meta.used`/`meta.limit` on a successful metered read lets you show "3 of 10 this month"
    without a second call. Metering is **per component per calendar month**; components no longer
    repeat, so there is exactly one A1.
- Payment status and retry are public (no token): see Payments below.
- `GET`/`PUT`/`DELETE /products/{product}/rating` — the subscriber's own 1–5 star rating. `PUT` is
  `403` unless they can actually read the product, so show the star control only when the product
  came back with `locked: false` or `redacted: true`.

### Admin Portal (token, role `admin` / `System Admin`)
- Users: `GET/POST /admin/users`, `GET/PATCH /admin/users/{user}`,
  `GET/PUT /admin/users/{user}/roles`, `GET/PUT /admin/users/{user}/permissions`.
  `POST` takes **no password** — drop that field from the create-user form. The backend generates
  one and emails it to the new user with their admin sign-in link. Check **`meta.accountEmailSent`**:
  if `false`, mail failed and **`meta.temporaryPassword`** holds the generated password for the admin
  to share — show it once; it is never returned when the email went out.
  **Staff accounts only** — a subscriber `publicId` here returns `404`, and `subscriber` is not an
  assignable role (`422`). Subscribers live under `/admin/subscribers`.
- Roles: full CRUD at `/admin/roles` (`{role}` is the role **name**, URL-encoded; payloads carry an
  optional free-text `description`, and omitting it on `PUT` leaves it untouched) plus
  `GET/PUT /admin/roles/{role}/permissions` to set what a role grants, and `GET /admin/permissions`
  for the vocabulary. Admins create their own roles (e.g. a Proofreader limited to the task board);
  the three built-in roles are flagged `isSystem` and reject edits with `protected_role`.
  Admin-portal access is permission-gated per section — see the table in API_REFERENCE.md §9.
- Subscribers: `GET /admin/subscribers?search=&status=&tier=`, `GET/PATCH /admin/subscribers/{subscriber}`.
- Audit log: `GET /admin/audit-logs?description=&from=&to=`.
- Transaction history: `GET /admin/transactions?search=&status=&method=&gateway=&subscriber=&from=&to=`,
  `GET /admin/transactions/{transaction}`. Every payment with its payer, gateway reference, M-Pesa
  transaction code and invoice; `search` matches the transaction code too. Includes pending and failed ones. Needs the `view transaction history` permission.
- Analytics: `GET /admin/analytics/summary`, `/subscriptions-by-tier`, `/products-by-component`,
  `/subscribers-by-location`, `/most-read-products?from=&to=&limit=`, `/product-ratings`,
  `/rejections`. All behind `view analytics`.
- Vault (all products incl. hidden): `GET /admin/vault/components`,
  `/vault/components/{component}/products` (rows carry `status`, `isHidden`, `readsCount`, ratings),
  **`GET /admin/vault/products/{product}`** for one product in full at any status, and
  `POST /admin/products/{product}/hide` | `/unhide`.
- Task Board (proofreading), now **two review stages**:
  `GET /admin/tasks?status=`, then per task
  `POST /admin/tasks/{task}/open` → `/proofread` (`{ body }`) → **approved**. The `/redact` stage is switched off (`PUBLISHING_REDACTION=false`), so `awaiting_redaction` never occurs — keep the column, it returns when the pass is re-enabled
  (`{ redacted_body }`), or `/reject` (`{ note }`). `/approve` skips the redaction stage.
  Transitions are guarded — an out-of-order call returns 409 `invalid_task_transition`.
  **The abstract is derived at the `/proofread` step** from the body's own Executive Summary, so a generated
  product has `abstract: null` until then, and cannot be released without it.
- Product Generation Master: `GET/POST /admin/topics` (create a topic to generate from — `component`
  only, no `pillar`), `POST /admin/topics/{topic}/queue` (kick off generation), `GET /admin/generation-queue`.
  `prompt_text`/`qa_prompt_text` are now **optional**: omit them and the component's client-supplied
  prompt is used — send `variables` (the placeholder values) instead. Each topic carries
  `createdBy: { publicId, name }` (null on an auto topic). `DB`, `WH` and `MF` create
  their own topics on a schedule, so expect rows there nobody filed.

> **Approving is not publishing.** An approved product joins a FIFO release queue and goes live on a
> scheduled job (hourly, oldest approval first, a fixed batch each run). So a product can be
> `approved` and visible in the vault while still absent from the catalogue — that is expected, not a
> bug. See §12 of `API_REFERENCE.md`.

### Webhooks (server-to-server)
`POST /webhooks/payments/{gateway}` — the payment gateway calls this; not a frontend concern.

## Payments (frontend flow)

Production uses PGW (M-Pesa STK push or a hosted card page); a fake driver stands in locally.

1. Register on a paid tier (or renew / upgrade) → `payment` + `instructions`.
   - `instructions.type: "mpesa"` → show `instructions.message`; the payer approves on their phone.
   - `instructions.type: "card"` → send the browser to `instructions.checkoutUrl`. PGW returns the payer
     to **`/payment/return?payment={publicId}`** on the subscriber portal. Build that page: it only
     polls, since the redirect itself settles nothing.
2. Poll **`GET /payments/{publicId}/status`** — **no token needed**, because a paid signup has none yet —
   every 3–5 seconds until `successful` (the signup can now log in) or `failed`.
3. On `failed`, show `failureReason` (`declined`, `expired`, `gateway_error`, `amount_mismatch`) and offer
   **`POST /payments/{publicId}/retry`** (optional `payment_method` to switch to card, optional `phone`).
   It returns a **new** payment: poll that one. An unanswered M-Pesa prompt turns `failed`/`expired`
   after the timeout (30 minutes by default), because PGW never reports it.
4. A pending user who logs in gets `403 payment_pending` with `meta.payment`: resume from step 2 or 3.

Money fields: `amount`/`currency` are what's charged (KES with PGW); `listAmount`/`listCurrency` are the
USD tier price. `GET /tiers` carries `charge: { amount, currency }` when the two differ, so the pricing
page can show "≈ KES 6,449" before M-Pesa does.

M-Pesa, the default method, needs a Kenyan mobile (`07…`, `01…`, `+254…`); anything else is `422`, so
offer card instead.

To simulate the gateway in dev, POST to `/api/v1/webhooks/payments/fake` with
`{ "gateway_ref": "<the payment's gatewayRef>", "result": "success" }` (`"failed"` for a decline). The
`gatewayRef` is on `GET /admin/transactions`.

## Local base URL

`http://127.0.0.1:8000/api/v1` (with `php artisan serve`). Seeded admin for testing:
`admin@spectrumglobalanalytics.test` / `password`.
