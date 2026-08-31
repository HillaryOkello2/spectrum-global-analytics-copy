# Frontend API Guide — Spectrum Global Analytics

Companion to the auto-generated reference. This page is the mental model; the full
per-endpoint reference (request/response schemas, try-it-out, code samples) lives at:

- **Interactive docs:** `GET /docs` (run the app, open `http://127.0.0.1:8000/docs`)
- **OpenAPI spec:** `GET /docs.openapi` → import into any client/codegen
- **Postman collection:** `GET /docs.postman`

Everything is under `/api/v1`. JSON only.

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
- `/proofread` now **requires `title`** and accepts `byline`.
- `DB`, `WH` and `MF` now **commission their own topics** on a schedule — those topics come back
  with `"source": "auto"`, `promptText: null` and a `variables` object.

## ⚠️ Breaking: Pillars are gone (2026-08)

The client removed the catalogue's top level. **Components are now the entry point and there are nine
of them (A1–A9).** Read the "Breaking changes" table at the top of `API_REFERENCE.md` before your next
integration pass — the short version:

- `/catalog/pillars*`, `/admin/vault/pillars*` and `/admin/analytics/products-by-pillar` are **gone**.
  Use `/catalog/components`, `/admin/vault/components`, `/admin/analytics/products-by-component`.
- No `pillar` object appears on anything any more. `{component}` accepts a code (`DB`) or a `publicId`.
- `firstParagraph` → **`abstract`** (written by a proofreader, not the LLM). Products gained a
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
- `payment_pending` / `account_suspended` (403) — login states above.

## The four areas

### Authentication (public)
`register`, `login`, `logout`, `forgot-password`, `reset-password`.

### Public Catalogue (public, no token)
Browse without logging in (FR-08):
- `GET /catalog/components` — the 9 Components, in `sortOrder`.
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
- `GET /me`, `PATCH /me`, `PUT /me/password` — profile + password.
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
- `GET /payments/{payment}/status` — poll after initiating a paid action (status: `pending`→`successful`/`failed`).
- `GET`/`PUT`/`DELETE /products/{product}/rating` — the subscriber's own 1–5 star rating. `PUT` is
  `403` unless they can actually read the product, so show the star control only when the product
  came back with `locked: false` or `redacted: true`.

### Admin Portal (token, role `admin` / `System Admin`)
- Users: `GET/POST /admin/users`, `GET/PATCH /admin/users/{user}`,
  `GET/PUT /admin/users/{user}/roles`, `GET/PUT /admin/users/{user}/permissions`.
  **Staff accounts only** — a subscriber `publicId` here returns `404`, and `subscriber` is not an
  assignable role (`422`). Subscribers live under `/admin/subscribers`.
- Roles: full CRUD at `/admin/roles` (`{role}` is the role **name**, URL-encoded) plus
  `GET/PUT /admin/roles/{role}/permissions` to set what a role grants, and `GET /admin/permissions`
  for the vocabulary. Admins create their own roles (e.g. a Proofreader limited to the task board);
  the three built-in roles are flagged `isSystem` and reject edits with `protected_role`.
  Admin-portal access is permission-gated per section — see the table in API_REFERENCE.md §9.
- Subscribers: `GET /admin/subscribers?search=&status=&tier=`, `GET/PATCH /admin/subscribers/{subscriber}`.
- Audit log: `GET /admin/audit-logs?description=&from=&to=`.
- Transaction history: `GET /admin/transactions?search=&status=&method=&gateway=&subscriber=&from=&to=`,
  `GET /admin/transactions/{transaction}`. Every payment with its payer, gateway reference and
  invoice. Includes pending and failed ones. Needs the `view transaction history` permission.
- Analytics: `GET /admin/analytics/summary`, `/subscriptions-by-tier`, `/products-by-component`,
  `/subscribers-by-location`, `/most-read-products?from=&to=&limit=`, `/product-ratings`,
  `/rejections`. All behind `view analytics`.
- Vault (all products incl. hidden): `GET /admin/vault/components`,
  `/vault/components/{component}/products`; `POST /admin/products/{product}/hide` | `/unhide`.
- Task Board (proofreading), now **two review stages**:
  `GET /admin/tasks?status=`, then per task
  `POST /admin/tasks/{task}/open` → `/proofread` (`{ title, byline, abstract, body }`) → `/redact`
  (`{ redacted_body }`), or `/reject` (`{ note }`). `/approve` skips the redaction stage.
  Transitions are guarded — an out-of-order call returns 409 `invalid_task_transition`.
  **The abstract is authored at the `/proofread` step** — the LLM never writes one, so a generated
  product has `abstract: null` until then, and cannot be released without it.
- Product Generation Master: `GET/POST /admin/topics` (create a topic to generate from — `component`
  only, no `pillar`), `POST /admin/topics/{topic}/queue` (kick off generation), `GET /admin/generation-queue`.
  `prompt_text`/`qa_prompt_text` are now **optional**: omit them and the component's client-supplied
  prompt is used — send `variables` (the placeholder values) instead. `DB`, `WH` and `MF` create
  their own topics on a schedule, so expect rows there nobody filed.

> **Approving is not publishing.** An approved product joins a FIFO release queue and goes live on a
> scheduled job (hourly, oldest approval first, a fixed batch each run). So a product can be
> `approved` and visible in the vault while still absent from the catalogue — that is expected, not a
> bug. See §12 of `API_REFERENCE.md`.

### Webhooks (server-to-server)
`POST /webhooks/payments/{gateway}` — the payment gateway calls this; not a frontend concern.

## Payments (frontend flow)

The real gateway (PGW) isn't wired yet — a fake driver runs locally so the flow is fully testable:

1. Register on a paid tier → you get `payment.publicId` + `instructions`.
2. In local/UAT, "paying" = the gateway calling the webhook. To simulate success in dev, POST to
   `/api/v1/webhooks/payments/fake` with `{ "gateway_ref": "<the payment's gatewayRef>", "result": "success" }`.
3. Poll `GET /payments/{payment}/status` until `successful`, then the account is active and the user can log in.

When PGW is integrated the `instructions` payload will carry the real STK-push / card-checkout details;
the poll-then-login flow stays the same.

## Local base URL

`http://127.0.0.1:8000/api/v1` (with `php artisan serve`). Seeded admin for testing:
`admin@spectrumglobalanalytics.test` / `password`.
