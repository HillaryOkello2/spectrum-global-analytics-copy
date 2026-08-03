# Spectrum Global Analytics — API Reference (Frontend)

Complete, standalone reference for the backend REST API. You can build against this
document without the server running. It covers every endpoint: the URL, whether a
token is required, path/query params, the request body (with validation rules), and
example success and error responses.

- **Base URL (local):** `http://localhost:8000/api/v1`
- **Format:** JSON only. Send `Accept: application/json` and, for bodies, `Content-Type: application/json`.
- **Live interactive version** (when the server is up): `http://localhost:8000/docs`
  (OpenAPI at `/docs.openapi`, Postman at `/docs.postman`).

> **Calling through ngrok?** Send `ngrok-skip-browser-warning: true` on every request, or the free
> tier returns its HTML interstitial instead of your JSON (and without CORS headers, so the browser
> reports it as a CORS failure). E.g. `axios.defaults.headers.common['ngrok-skip-browser-warning'] = 'true'`.

> **The deployed backend looks empty in a browser — that's deliberate.**
> On `https://demo.sga-backend.techbizafrica.com`, everything except `/api/v1/*` returns a bare
> **404**: the root URL, `/docs`, `/horizon`, `/storage/*`. It is not down and it is not
> misconfigured — the non-API surface is hidden from the public internet on purpose.
>
> - **Your API calls are unaffected.** Same URLs, same payloads, same auth, same CORS.
>   `https://demo.sga-backend.techbizafrica.com/api/v1/...` works normally.
> - **`/docs` is not available on the deployed backend.** This file is the complete standalone
>   reference. For the interactive version, run the backend locally and open `/docs` there.
> - If a call ever returns `404` with an **empty body**, you hit a path outside `/api/v1/*`.
>   A genuine API 404 always carries a JSON `{ "message": ... }`.

---

## ⚠️ Breaking changes — 2026-08 scope change

**Pillars are gone.** The client removed the top level of the catalogue. Components are now
the entry point, there are **nine** of them (A1–A9, globally unique), and each has its own
assigned LLM. Everything below is a contract change — re-read this section before your next
integration pass, then work through the [migration checklist](#frontend-migration-checklist).

| What changed | Do this instead |
|---|---|
| `GET /catalog/pillars` — **removed** | `GET /catalog/components` — a flat list of 9 |
| `GET /catalog/pillars/{pillar}/components` — **removed** | `GET /catalog/components` |
| `GET /admin/vault/pillars` — **removed** | `GET /admin/vault/components` |
| `GET /admin/vault/pillars/{pillar}/components` — **removed** | `GET /admin/vault/components` |
| `GET /admin/analytics/products-by-pillar` — **removed** | `GET /admin/analytics/products-by-component` (keys are now `component` + `code`) |
| No `pillar` object appears on any component, topic or product payload | Read `component` directly |
| `POST /admin/topics` no longer accepts `pillar` | Send `component` alone |
| `{component}` accepts a component **code** (`A4`) as well as a `publicId` | — |
| Products lost `firstParagraph` | Read **`abstract`** — written by a proofreader, not the LLM |
| Products gained **`code`** (`SGA.A4.2026-08.017`) | Show it wherever you show a title |
| A third content level: **`redactedBody`** | See [the content ladder](#the-content-ladder) |
| Task board gained the status **`awaiting_redaction`** and two actions | See [Task Board](#task-board--proofreading-workflow-fr-4445-184) |
| **Approving no longer publishes.** Approved products go live on a scheduled FIFO release | See [§12](#12-the-content-lifecycle-how-products-appear) |

### Earlier changes

| Change | Where |
|---|---|
| Admins can **create roles and set what each grants**; admin-portal access is **permission-based per section**, not role-based | [§9 Permissions & access control](#permissions--access-control), [Roles & permissions](#roles--permissions-fr-39) |
| `/admin/users` is **staff-only** — subscriber ids return `404`, and `subscriber` is not assignable | [§9 Users & roles](#users--roles) |
| Components carry **`productsCount`** so you can badge them before opening | [§7 Public Catalogue](#7-public-catalogue-endpoints) |
| **`GET /auth/me`** — role-agnostic session rehydration; use it instead of `/me`, which is subscriber-only | [§6 Authentication](#6-authentication-endpoints) |
| `UserResource` carries `permissions` and `directPermissions` alongside `roles` | [§9 Permissions & access control](#permissions--access-control) |
| Subscription **renew** and **upgrade** endpoints | [§8](#8-subscriber-portal-endpoints) |

---

## Frontend migration checklist

The table above is *what the API does now*. This is *what you have to change*, ordered by blast
radius. Items marked **silent** are the dangerous ones: nothing errors, the UI just goes blank or
shows the wrong thing.

### 1. Delete the pillar level from routing and navigation — **large**

The catalogue is one level shallower. Any route like `/pillars`, `/pillars/:pillarCode`, or
`/pillars/:pillarCode/components/:componentId` collapses to `/components` and
`/components/:componentCode`.

- The pillar list page, the pillar tile/card component, and any pillar breadcrumb segment are all
  dead code — delete them.
- Breadcrumbs go from `Home › Pillar › Component › Product` to `Home › Component › Product`.
- **Old pillar URLs will 404.** If any are already shared or bookmarked, add a redirect from
  `/pillars/*` → `/components`.
- Any pillar filter in a dropdown, sidebar or query string comes out.

Landing page: what was a 12-tile pillar grid is now a **9-tile component grid** from a single
`GET /catalog/components` call. That call also replaces the old `?include=components` round trip —
there is no nesting left to request.

### 2. Swap `firstParagraph` → `abstract` — **small but silent**

Every place you rendered `firstParagraph` now reads `abstract`. Nothing 404s; the teaser just renders
empty.

```diff
- <p>{product.firstParagraph}</p>
+ <p>{product.abstract}</p>
```

Note `abstract` **can be `null`** on an unpublished product (it is written during proofreading, not
generated). Public and subscriber endpoints only ever return published products, so it is reliably
present there — but the **admin task board will show `null`**, and that is correct, not a bug.

### 3. Handle the third content level on `GET /products/{product}` — **medium, new feature**

The product view previously had two states. It now has three, plus the quota error. **Branch on
`locked` and `redacted`, not on which fields are present:**

```js
if (!data.locked)      → render data.body          // full access
else if (data.redacted) → render data.redactedBody  // upgrade CTA above it
else                    → render data.abstract      // locked teaser + Subscribe CTA
// plus: 403 quota_exhausted → upgrade prompt (unchanged)
```

`redacted: true` is a **new UI state** and needs a design: it is readable content behind an upgrade
prompt, not a hard lock. `meta.reason` is `redacted_access` for this case.

### 4. Show the product `code` — **small, additive**

Every product payload now carries `code` (`SGA.A4.2026-08.017`). Add it to product cards, the product
header, and the admin vault/task rows — it is what staff and subscribers will quote to each other.

> Display only. **Do not put it in a URL** — routing still uses `publicId`. It *is* searchable:
> `GET /catalog/components/{component}/products?search=` matches title **or** code.

### 5. Rebuild the proofreading screen for two stages — **large, admin only**

The task board gains a status (`awaiting_redaction`) and two endpoints. The single "Approve" button
becomes a two-step flow:

| Task status | What the screen shows | Action |
|---|---|---|
| `awaiting_proofreading` | Read-only review | **Open** → `/open` |
| `in_proofreading` | Editable **abstract** (starts empty) + editable **body** | **Submit proofread** → `/proofread` |
| `awaiting_redaction` | Read-only body + editable **redacted body** | **Submit redaction** → `/redact` |
| `approved` | Done — waiting for release | none |

- Add a **`awaiting_redaction` column/filter** to the board, or it will look like tasks vanish
  mid-workflow. **silent**
- The abstract editor must start **empty** and be clearly labelled as the public-facing summary. Do
  not prefill it from the body — that would publish the opening of the article as the teaser.
- Both new endpoints validate: `abstract` (required, ≤5000) + `body` (required), and `redacted_body`
  (required). Out-of-order calls return `409 invalid_task_transition` — same handling you already have.
- `/approve` still exists and still works, for products that need no redaction pass.

### 6. Stop telling users "Approved = live" — **small, but a support-ticket generator**

Approving now queues a product for a scheduled FIFO release (hourly, oldest approval first, a fixed
batch per run). A product can sit in `approved` for a while.

- Change any "Published!" confirmation after approve to something like *"Approved — queued for
  release."*
- Expect `approved` products to appear in the **vault** but **not** the public catalogue. That gap is
  correct; make sure it doesn't get filed as a bug.

### 7. Update the admin endpoints you already call — **small**

| Was | Now |
|---|---|
| `/admin/vault/pillars` + `/admin/vault/pillars/{pillar}/components` (two calls) | `/admin/vault/components` (one) |
| `/admin/analytics/products-by-pillar` | `/admin/analytics/products-by-component` |
| Analytics row keys `{ pillar, code, productsPublished }` | `{ component, code, productsPublished }` |
| LLM map: provider `.pillars[]` | provider `.components[]` |
| `POST /admin/topics` body with `pillar` + `component` | `component` only — the topic form loses a select |

### 8. Search-and-replace sweep

Grep the frontend for each of these; every hit is a change:

```
pillar        Pillar        PILLAR
firstParagraph
products-by-pillar
vault/pillars
catalog/pillars
SGA.P            ← pillar codes like SGA.P1 hardcoded anywhere
```

### Things that did NOT change

So you don't re-test them: auth and tokens, the whole payment flow, subscriber profile /
subscription / invoices, users / roles / permissions, the audit log, hide/unhide, pagination and
envelope shapes, error codes, and `productsCount` semantics (still visible-only in public, everything
in the vault).

`A10`–`A14` no longer exist. If you hardcoded component codes anywhere — a tier comparison table, an
icon map, sort order — trim to `A1`–`A9`. Pay-to-own (`A14`) is gone with them, so any
purchase/pay-to-own UI is currently unreachable; the backend keeps the endpoints wired for when the
client brings it back.

---

## Table of contents

0. [⚠️ Breaking changes — 2026-08 scope change](#️-breaking-changes--2026-08-scope-change)
0b. [Frontend migration checklist](#frontend-migration-checklist) ← **start here**
1. [Core concepts](#1-core-concepts)
2. [Authentication & tokens](#2-authentication--tokens)
3. [Response conventions](#3-response-conventions)
4. [Error handling](#4-error-handling)
5. [Enums (allowed values)](#5-enums-allowed-values)
6. [Authentication endpoints](#6-authentication-endpoints)
7. [Public Catalogue endpoints](#7-public-catalogue-endpoints)
8. [Subscriber Portal endpoints](#8-subscriber-portal-endpoints)
9. [Admin Portal endpoints](#9-admin-portal-endpoints)
10. [Webhooks](#10-webhooks)
11. [The payment flow (step by step)](#11-the-payment-flow-step-by-step)
12. [The content lifecycle (how products appear)](#12-the-content-lifecycle-how-products-appear)
13. [Appendix: subscription tier matrix](#13-appendix-subscription-tier-matrix)

---

## 1. Core concepts

The platform is a subscription intelligence service with three audiences, each backed by a set of endpoints:

- **Public** — anyone, no login. Browse the catalogue and see gated previews.
- **Subscriber** — a logged-in paying (or Freemium) user. Reads full content within their tier, manages their account.
- **Admin** — internal staff. Manage content generation, proofreading, users, subscribers, analytics.

**Content hierarchy** is two levels:

```
Component  (9 of them, e.g. "Daily Strategic Intelligence Analytics Brief", code A4)
  └── Product  (the actual articles subscribers read)
```

A **Topic** (admin-created) is the instruction that generates Products for a given Component on a
schedule. Each Component also has its own assigned LLM, which is the model that writes its products.

The nine components, in `sortOrder`:

| Code | Name | Batch |
|---|---|---|
| `A1` | SGA Analytics Abstract Papers (AP) | 1 |
| `A2` | SGA Analytics Essay Series (ES) | 1 |
| `A3` | Analytics Book Series (BS) | 1 |
| `A4` | Daily Strategic Intelligence Analytics Brief (DB) | 2 |
| `A5` | Weekly Strategic Intelligence Analytics Highlights (WH) | 2 |
| `A6` | Monthly Strategic Intelligence Analytics Focus (MF) | 2 |
| `A7` | Strategic Analytics Research Papers (RP) | 3 |
| `A8` | Analytics White Papers - Corporate (WP/C) | 3 |
| `A9` | Analytics White Papers - Governmental (WP/G) | 3 |

### The content ladder

Every product carries up to three levels of content. Which one you receive depends on the caller's
entitlement — the field that is absent is genuinely not in the response, so you cannot leak it by
accident:

| Caller | Field served | `locked` |
|---|---|---|
| Visitor, or a subscriber with no active subscription | `abstract` | `true` |
| Subscriber whose tier **denies** this component, or whose metered quota is spent, **and** an approved redaction exists | `redactedBody` (plus `abstract`, and `redacted: true`) | `true` |
| Subscriber within their tier allocation | `body` (plus `abstract`) | `false` |

If the tier withholds the document and there is **no** approved redaction, an exhausted metered
quota is still a `403 quota_exhausted`; a denied allocation still returns the abstract alone.

The **`abstract` is written by a proofreader, never by the LLM.** A freshly generated product has a
`body` but no `abstract`, and cannot be published until one exists.

**Everything is addressed by `publicId`** — an opaque UUID like `9b1f...`. Internal numeric IDs are never exposed. Wherever a URL shows `{component}`, `{product}`, `{invoice}`, etc., you pass the `publicId`. `{component}` additionally accepts the component **code** (`A4`).

Separately, every product has a human-readable **`code`**: `SGA.{component}.{period}.{sequence}`,
e.g. `SGA.A4.2026-08.017`. The period is the month (`2026-08`) for daily/weekly/monthly series and
the quarter (`2026-Q3`) for quarterly ones; the sequence restarts each period. It is unique and
safe to display, but it is **not** a URL identifier — use `publicId` for that.

---

## 2. Authentication & tokens

Auth uses **bearer tokens** (Laravel Sanctum). There are no cookies or CSRF to manage for the API.

**How to get a token:**
- `POST /auth/login` returns a `token`.
- `POST /auth/register` on a **Freemium** tier returns a `token` immediately (no payment). Paid tiers do **not** return a token until payment completes and the user logs in.

**How to use it:** send this header on every authenticated request:

```
Authorization: Bearer 12|Xy9AbC...the-token-string
```

**What a token can reach:**
- A **subscriber** token works on Subscriber endpoints only. Any `/admin/*` endpoint returns `403`.
- A **staff** token reaches the parts of `/admin/*` its permissions allow — see [Permissions & access control](#permissions--access-control). It cannot use the Subscriber portal endpoints (`403`), which are subscriber-role-only.
- Use the `portal` field from the login response (`"subscriber"` or `"admin"`) to decide where to route the user after login, and the `permissions` array to decide which admin navigation to render.

**Rehydrating a session:** on page refresh you hold a token but no user object. `GET /auth/me` returns the user and their `portal` for **any** authenticated role — use it instead of `GET /me`, which is subscriber-only and returns `403` for staff.

**Logout:** `POST /auth/logout` revokes the current token.

---

## 3. Response conventions

- **Single resource:** wrapped in `data`.
  ```json
  { "data": { "publicId": "…", "title": "…" } }
  ```
- **Lists:** either a plain array under `data` (small fixed lists like components/tiers) or a **paginated** envelope:
  ```json
  {
    "data": [ { "publicId": "…" }, { "publicId": "…" } ],
    "links": { "first": "…", "last": "…", "prev": null, "next": "http://localhost:8000/api/v1/…?page=2" },
    "meta": { "current_page": 1, "from": 1, "last_page": 5, "per_page": 20, "to": 20, "total": 92, "path": "…" }
  }
  ```
- **Keys are camelCase** (`publishedAt`, `isTransactional`, `redactedBody`).
- **Dates** are strings: `"2026-07-15 12:11:57"` (datetimes) or `"2026-07-15"` (dates like invoice issue date). `null` when not set.
- **Money** (`price`, `amount`) is a decimal string, e.g. `"49.99"`. `currency` is a 3-letter code, e.g. `"USD"`.

---

## 4. Error handling

All errors are JSON. Check the HTTP status first, then `code` for domain errors.

| Status | Meaning | Body shape |
|---|---|---|
| `401` | No/invalid token on a protected route | `{ "message": "Unauthenticated." }` |
| `403` | Wrong role, or a business rule blocked you | `{ "message": "…", "code": "…", "meta"?: {…} }` |
| `404` | Not found — or content that is hidden/unpublished/not yours | `{ "message": "…" }` |
| `409` | Workflow conflict (invalid task transition) | `{ "message": "…", "code": "invalid_task_transition" }` |
| `422` | Validation failed | `{ "message": "…", "errors": { "field": ["reason"] } }` |

**Validation error example (422):**
```json
{
  "message": "The email field is required. (and 1 more error)",
  "errors": {
    "email": ["The email field is required."],
    "password": ["The password field confirmation does not match."]
  }
}
```

**Domain error `code` values you should handle:**

| `code` | Status | What it means | UI action |
|---|---|---|---|
| `quota_exhausted` | 403 | Metered monthly limit for this component reached. Body has `meta.used` / `meta.limit`. | Show "upgrade / limit reached". |
| `subscription_not_active` | 403 | No active subscription. | Prompt to subscribe/renew. |
| `payment_pending` | 403 | Login blocked: account registered but payment not completed. | Send back to payment step. |
| `account_suspended` | 403 | Login blocked: admin suspended the account. | Show support message. |
| `invalid_task_transition` | 409 | (Admin) task board action not valid from current status. | Refresh the task, re-render available actions. |
| `invalid_tier_change` | 422 | Upgrade target is the same or a lower/equal-priced tier. | Only offer higher tiers; use renew for the same tier. |
| `payment_callback_mismatch` | 422 | (Webhook) callback didn't match a pending payment. | Server-to-server only. |
| `tier_not_purchasable` | 422 | Selected tier is inactive/unavailable. | Refresh tiers. |
| `role_escalation` | 403 | (Admin) only a System Admin may grant/revoke the System Admin role. | Hide that option for non-System-Admins. |
| `self_access_change` | 422 | (Admin) you cannot change your own roles/permissions. | Disable the control on your own row. |
| `last_system_admin` | 422 | (Admin) would remove the only System Admin. | Explain that another must be promoted first. |
| `protected_role` | 422 | (Admin) built-in roles cannot be renamed, re-permissioned or deleted. | Hide those controls when `isSystem` is true. |
| `role_in_use` | 422 | (Admin) role still assigned to users; `meta.usersCount` has the number. | Offer to reassign those users first. |

---

## 5. Enums (allowed values)

| Enum | Values | Used in |
|---|---|---|
| **UserStatus** | `pending`, `active`, `suspended` | user `status` |
| **SubscriptionStatus** | `pending`, `active`, `expired`, `cancelled` | subscription `status` |
| **PaymentMethod** | `mpesa`, `card` | register `payment_method`, payment `method` |
| **PaymentStatus** | `pending`, `successful`, `failed` | payment `status` |
| **AccessType** | `unlimited`, `metered`, `denied` | tier allocation `accessType` |
| **ProductStatus** | `draft`, `awaiting_proofreading`, `in_proofreading`, `rejected`, `approved`, `published` | product `status` (admin/vault) |
| **TaskStatus** | `queued`, `generating`, `qa_running`, `awaiting_proofreading`, `in_proofreading`, `approved`, `rejected`, `published`, `failed` | generation task `status` |
| **Frequency** | `daily`, `weekly`, `monthly`, `quarterly` | topic `frequency` |
| **TransactionTier** | `premium`, `mid_range`, `standard` | Pay-to-own purchases (dormant — no transactional component is seeded) |

Subscription tiers (by name): **Freemium**, **Premium**, **Superior**, **Platinum**. Freemium is free and activates instantly; the others require payment.

---

## 6. Authentication endpoints

Base: `/auth`. No token required (except logout).

### POST `/auth/register`
Create an account on a chosen tier.

**Body**

| Field | Rules |
|---|---|
| `first_name` | required, string, ≤255 |
| `last_name` | required, string, ≤255 |
| `phone` | required, string, ≤30 |
| `email` | required, email, ≤255, unique |
| `country` | required, string, ≤100 |
| `password` | required, must match `password_confirmation`, meets default strength |
| `password_confirmation` | required, must equal `password` |
| `tier` | required, a tier `publicId` (from `GET /tiers`) |
| `payment_method` | optional, `mpesa` or `card` (only relevant for paid tiers; defaults to `mpesa`) |

**Response — Freemium tier (`201`):** account is active, token returned.
```json
{
  "message": "Account activated.",
  "data": {
    "user": {
      "publicId": "…", "firstName": "Amina", "lastName": "Odhiambo",
      "email": "amina@example.com", "phone": "+254712345678", "country": "Kenya",
      "status": "active", "roles": ["subscriber"], "joinedAt": "2026-07-15 12:00:00"
    },
    "token": "12|Xy9AbC…"
  }
}
```

**Response — Paid tier (`201`):** account pending, complete payment next (no token yet).
```json
{
  "message": "Registration received. Complete payment to activate your account.",
  "data": {
    "payment": {
      "publicId": "…", "method": "mpesa", "amount": "49.99",
      "currency": "USD", "status": "pending", "paidAt": null
    },
    "instructions": {
      "type": "mpesa",
      "message": "Fake gateway: POST the callback endpoint with this gatewayRef to complete payment."
    }
  }
}
```
> `instructions` is gateway-specific. With the real gateway it will carry the STK-push prompt or card-checkout details. See [The payment flow](#11-the-payment-flow-step-by-step).

**Errors:** `422` validation (e.g. email taken, weak password, unknown tier).

---

### POST `/auth/login`
**Body:** `email` (required, email), `password` (required, string).

**Response (`200`):**
```json
{
  "data": {
    "user": { "publicId": "…", "firstName": "…", "status": "active", "roles": ["subscriber"], "subscription": { "…": "…" } },
    "token": "12|Xy9AbC…",
    "portal": "subscriber"
  }
}
```
`portal` is `"subscriber"` or `"admin"` — route the user accordingly.

**Errors:**
- `422` — wrong email/password: `{ "message": "…", "errors": { "email": ["These credentials do not match our records."] } }`
- `403 payment_pending` — registered but not paid.
- `403 account_suspended` — suspended by an admin.

---

### GET `/auth/me`  *(requires token — any role)*
Current session ("who am I"). Works for **any** authenticated account — unlike `GET /me`, which is
subscriber-only and returns `403` for staff. Call this on app boot / page refresh to rehydrate the user and decide which portal
to route into (the `portal` field is the redirect signal, same as in the login response).

**Response (`200`):**
```json
{
  "data": {
    "user": {
      "publicId": "…", "firstName": "…", "lastName": "…", "email": "…", "phone": "…", "country": "…",
      "status": "active", "joinedAt": "2026-07-15 12:00:00",
      "roles": ["admin"],
      "permissions": ["access admin portal", "manage users", "…"],
      "directPermissions": [],
      "subscription": null
    },
    "portal": "admin"
  }
}
```
- `portal` is `"admin"` for any staff account (any role other than `subscriber`) or `"subscriber"` otherwise. It is the redirect signal.
- `permissions` is the effective list — render your admin navigation from it, together with the [permission table](#permissions--access-control).
- `subscription` is `null` for staff and for subscribers without an active subscription.

**Errors:** `401` without a token.

---

### POST `/auth/logout`  *(requires token)*
Revokes the current token. **Response (`200`):** `{ "message": "Logged out." }`

---

### POST `/auth/forgot-password`
**Body:** `email` (required, email).
**Response (`200`):** `{ "message": "If that email address is registered, a reset link has been sent." }`
(Always this message — it never reveals whether the email exists.)

---

### POST `/auth/reset-password`
**Body:** `token` (from the email), `email`, `password`, `password_confirmation`.
**Response (`200`):** `{ "message": "Password has been reset." }`
**Errors:** `422` (bad/expired token or weak password).

---

## 7. Public Catalogue endpoints

No token required. These power the marketing site and gated previews.

### GET `/catalog/components`
Lists all 9 Components, in `sortOrder` (A1 → A9). This is the catalogue's entry point.

**Response (`200`):**
```json
{
  "data": [
    { "publicId": "…", "name": "SGA Analytics Abstract Papers (AP)", "code": "A1", "batch": 1, "isTransactional": false, "sortOrder": 1, "productsCount": 3 }
  ]
}
```

**`productsCount`** — how many products the component holds, so you can show a badge without opening it. On every public and subscriber endpoint it counts **published, non-hidden** products, exactly the ones `/catalog/components/{component}/products` would list.

> `productsCount` appears **only** on the catalogue and vault listings. It is deliberately **absent** from the `component` object embedded inside a product payload — don't read it there, use the listing endpoint.

---

### GET `/catalog/components/{component}/products`
Published, non-hidden products under a component. **Paginated** (20/page). `{component}` = component `publicId` **or** component `code` (e.g. `A4`) — codes are globally unique now, so both work everywhere a `{component}` parameter appears.

**Query:** `search` (optional, matches product title **or** code), `page`.

**Response (`200`):**
```json
{
  "data": [
    {
      "publicId": "…", "code": "SGA.A4.2026-08.017", "title": "Red Sea Chokepoint Risk Outlook",
      "excerpt": "The first 160 characters of the abstract…", "publishedAt": "2026-07-15 12:11:57",
      "component": {
        "publicId": "…", "code": "A4", "name": "Daily Strategic Intelligence Analytics Brief (DB)", "batch": 2, "isTransactional": false, "sortOrder": 4
      }
    }
  ],
  "links": { "first": "…", "last": "…", "prev": null, "next": null },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 }
}
```
> Listings never include the body — only a short `excerpt` taken from the **abstract**. Every product embeds its `component`, so you can resolve a product's place in the catalogue from the product payload alone.

---

### GET `/products/{product}/preview`
The gated teaser for any published product (FR-09). `{product}` = product `publicId`.

**Response (`200`):**
```json
{
  "data": {
    "publicId": "…", "code": "SGA.A4.2026-08.017", "title": "Red Sea Chokepoint Risk Outlook",
    "abstract": "The proofreader-written abstract, in full…",
    "locked": true,
    "publishedAt": "2026-07-15 12:11:57",
    "component": { "publicId": "…", "code": "A4", "name": "…", "batch": 2, "isTransactional": false, "sortOrder": 4 }
  }
}
```
Render the `abstract`, a lock icon, and a Subscribe button. Neither `body` nor `redactedBody` is ever present on this endpoint. **Errors:** `404` if the product isn't published or is hidden.

---

### GET `/tiers`
Subscription tier cards for the pricing/subscription page, including per-component allocations.

**Response (`200`):**
```json
{
  "data": [
    {
      "publicId": "…", "name": "Freemium", "price": "0.00", "currency": "USD", "billingPeriod": "monthly",
      "allocations": [
        { "componentCode": "A1", "componentName": "SGA Analytics Abstract Papers (AP)", "accessType": "metered", "monthlyLimit": 10 },
        { "componentCode": "A4", "componentName": "Daily Strategic Intelligence Analytics Brief (DB)", "accessType": "denied", "monthlyLimit": null }
      ]
    }
  ]
}
```
`accessType` is `unlimited` (no cap), `metered` (`monthlyLimit` reads per month), or `denied` (not included). See the [tier matrix appendix](#13-appendix-subscription-tier-matrix).

---

## 8. Subscriber Portal endpoints

**All require a subscriber token** (`Authorization: Bearer …`). Return `401` without a token, `403` with a non-subscriber token.

### GET `/dashboard`
Active subscription card + recent products.

**Response (`200`):**
```json
{
  "data": {
    "subscription": { "publicId": "…", "status": "active", "startsAt": "…", "endsAt": "…", "tier": { "name": "Premium", "…": "…" } },
    "recentProducts": [
      { "publicId": "…", "code": "SGA.A1.2026-08.003", "title": "…", "excerpt": "…", "publishedAt": "…",
        "component": { "publicId": "…", "code": "A1", "name": "…", "batch": 1, "isTransactional": false, "sortOrder": 1 } }
    ]
  }
}
```
`subscription` is `null` if the user has no active subscription.

---

### GET `/me`
Current user's profile.

**Response (`200`):**
```json
{
  "data": {
    "publicId": "…", "firstName": "Amina", "lastName": "Odhiambo", "email": "amina@example.com",
    "phone": "+254712345678", "country": "Kenya", "status": "active", "roles": ["subscriber"],
    "joinedAt": "2026-07-15 12:00:00",
    "subscription": { "publicId": "…", "status": "active", "startsAt": "…", "endsAt": "…", "tier": { "name": "Premium" } }
  }
}
```

### PATCH `/me`
Update profile. **Body (all optional):** `first_name`, `last_name`, `phone`, `country`, `email` (unique, ignoring self). Returns the updated user (same shape as `GET /me`).

### PUT `/me/password`
**Body:** `current_password` (must match existing), `password`, `password_confirmation`.
**Response (`200`):** `{ "message": "Password updated." }`. Other sessions' tokens are revoked; the current one stays valid. **Errors:** `422` if `current_password` is wrong or new password is weak.

---

### GET `/me/subscription`
Full subscription detail (tier + allocations) for the "active subscription" area.

**Response (`200`):** a `SubscriptionResource` (with nested `tier.allocations`). **Errors:** `403 subscription_not_active` if the account has no subscription at all.

---

### POST `/me/subscription/renew`
Renew the current subscription for **another month on the same tier**. A month is added from
whichever is later — now or the current end date — so renewing early never loses remaining days.

**Body:** `payment_method` (optional, `mpesa` or `card`; only used for paid tiers).

**Response — Freemium (`200`):** renewed immediately, no payment.
```json
{ "message": "Subscription renewed.", "data": { "subscription": { "publicId": "…", "status": "active", "endsAt": "…", "tier": { "name": "Freemium" } } } }
```

**Response — paid tier (`202`):** complete payment to finish (same pattern as sign-up).
```json
{ "message": "Complete payment to renew your subscription.", "data": { "payment": { "publicId": "…", "status": "pending", "amount": "49.99" }, "instructions": { "…": "…" } } }
```
Then poll `GET /payments/{payment}/status`; on success the term is extended. **Errors:** `403 subscription_not_active` if there's no subscription to renew.

---

### POST `/me/subscription/upgrade`
Move to a **higher tier, charged at the full price of the new tier**. The change takes effect once
payment clears, starting a **fresh one-month term** on the new tier.

**Body:** `tier` (required, target tier `publicId`), `payment_method` (optional, `mpesa`/`card`).

**Response (`202`):** always requires payment (upgrades are to a higher-priced tier).
```json
{ "message": "Complete payment to upgrade your subscription.", "data": { "payment": { "publicId": "…", "status": "pending", "amount": "199.99" }, "instructions": { "…": "…" } } }
```
Poll `GET /payments/{payment}/status`; on success the subscription switches to the new tier with a fresh month. The tier does **not** change until payment clears.

**Errors:**
- `422 invalid_tier_change` — target is the same tier (use renew) or a lower/equal-priced tier (not an upgrade).
- `422 tier_not_purchasable` — target tier is inactive.
- `422` validation — unknown `tier`.

> **Note:** upgrade always charges the full new-tier price (no proration). Downgrades are not supported by this endpoint.

---

### GET `/me/invoices`
Paginated list of the user's invoices.

**Response (`200`):**
```json
{
  "data": [ { "publicId": "…", "number": "INV-2026-000001", "amount": "49.99", "currency": "USD", "issueDate": "2026-07-15", "lineItems": [ { "description": "Premium subscription (monthly)", "amount": "49.99" } ] } ],
  "links": { "…": "…" }, "meta": { "…": "…" }
}
```

### GET `/me/invoices/{invoice}`
One invoice (same object shape). **Errors:** `404` if it isn't the caller's invoice.

---

### GET `/products/{product}`  ⭐ the key content endpoint
Returns the **full document**, the **redacted document**, or the **abstract**, depending on entitlement — see [the content ladder](#the-content-ladder). `{product}` = product `publicId`.

Branch on `locked` and `redacted`, not on which fields happen to be present.

**Case A — full access (`200`):**
```json
{
  "data": {
    "publicId": "…", "code": "SGA.A1.2026-08.003", "title": "…",
    "abstract": "…", "body": "…full article text…",
    "locked": false, "publishedAt": "…", "component": { "code": "A1", "name": "…" }
  },
  "meta": { "access": "unlimited" }
}
```
For a **metered** component the `meta` shows usage so you can render "3 of 10 this month":
```json
{ "data": { "…": "…", "locked": false }, "meta": { "access": "metered", "used": 3, "limit": 10 } }
```

**Case B — redacted access (`200`):** the tier withholds the full document (denied, or metered quota spent) **and** an approved redaction exists.
```json
{
  "data": {
    "publicId": "…", "code": "SGA.A6.2026-08.001", "title": "…",
    "abstract": "…", "redactedBody": "…the separately proofread redacted document…",
    "locked": true, "redacted": true, "publishedAt": "…", "component": { "code": "A6", "name": "…" }
  },
  "meta": { "access": "denied", "reason": "redacted_access" }
}
```
Render the redacted text with an upgrade CTA above it. `body` is **not** present.

**Case C — abstract only (`200`):** denied or no active subscription, and no approved redaction.
```json
{
  "data": { "publicId": "…", "code": "…", "title": "…", "abstract": "…", "locked": true, "publishedAt": "…", "component": { "code": "A6", "name": "…" } },
  "meta": { "access": "denied", "reason": "denied" }
}
```
`reason` is `denied` or `preview_only`. Render the locked teaser + upgrade CTA.

**Case D — metered limit reached, no redaction to fall back to (`403`):**
```json
{ "message": "Monthly product limit reached for this component on your subscription tier.", "code": "quota_exhausted", "meta": { "used": 10, "limit": 10 } }
```

**Errors:** `404` if the product isn't published or is hidden.

> **Metering rule:** the limit is **per component per calendar month** — "A1 max 10/month" is 10 A1 products. Components no longer repeat, so there is exactly one A1. Re-reading a product you've already unlocked this month is free (doesn't consume quota again).

---

### GET `/payments/{payment}/status`
Poll a payment while it settles. `{payment}` = payment `publicId`.

**Response (`200`):**
```json
{ "data": { "publicId": "…", "method": "mpesa", "amount": "49.99", "currency": "USD", "status": "pending", "paidAt": null } }
```
Poll until `status` is `successful` (then the user can log in) or `failed` (retry). **Errors:** `404` if it isn't the caller's payment.

---

## 9. Admin Portal endpoints

**All require a staff token.** Prefixed with `/admin`. Access is **permission-based, not role-based**: entry requires the `access admin portal` permission and each section requires its own on top of it — see [Permissions & access control](#permissions--access-control) below. Anything not granted returns `403`.

The built-in `admin` role holds all eight permissions, so an admin reaches everything here. A `System Admin` bypasses every check. Custom roles reach exactly what they were granted.

### Permissions & access control

Entry to `/admin/*` requires the **`access admin portal`** permission, and each section then requires its own:

| Section | Permission |
|---|---|
| `/admin/users`, `/admin/roles`, `/admin/permissions` | `manage users` |
| `/admin/subscribers` | `manage subscribers` |
| `/admin/audit-logs` | `view audit logs` |
| `/admin/analytics/*` | `view analytics` |
| `/admin/vault/*`, `/admin/products/{product}/hide` \| `/unhide` | `manage vault` |
| `/admin/tasks/*`, `/admin/generation-queue` | `proofread products` |
| `/admin/topics/*`, `/admin/llm-providers` | `manage topics` |

So a role granting only `access admin portal` + `proofread products` can reach the task board and nothing else — everything else returns `403`. **A role without `access admin portal` cannot reach the admin portal at all**, however many other permissions it holds. Build your admin navigation from the user's `permissions` array and this table.

**Every `UserResource` carries three access fields:**
```json
{
  "roles": ["admin"],
  "permissions": ["access admin portal", "manage users", "…"],
  "directPermissions": ["proofread products"]
}
```
- `roles` — role names.
- `permissions` — **effective** permissions (from roles *plus* direct grants). Gate your UI on this one. For a `System Admin` this returns the complete permission list, because that role bypasses every check rather than holding permissions individually.
- `directPermissions` — granted to this user individually, on top of their roles. This is what the permissions editor edits.

### Users & roles

| Method | Path | Purpose |
|---|---|---|
| GET | `/admin/users` | List admin/staff users. Paginated. Query: `search`, `status`. |
| POST | `/admin/users` | Create a staff user. |
| GET | `/admin/users/{user}` | One user. |
| PATCH | `/admin/users/{user}` | Update a user. |

**POST body:** `first_name`, `last_name`, `phone`, `country`, `email` (unique), `password`, `roles` (array of role names, ≥1 — any staff role, e.g. `["admin"]` or a custom `["Proofreader"]`). Returns `201`. The account is created `active`, with no subscription.
**PATCH body (all optional):** `first_name`, `last_name`, `phone`, `country`, `status` (UserStatus), `roles` (array).
**Response:** `UserResource` (see `GET /me` shape). `{user}` = user `publicId`.

> ### ⚠️ These endpoints address **staff accounts only**
>
> `/admin/users` and its `roles`/`permissions` sub-routes operate exclusively on **staff** accounts — anyone holding a role other than `subscriber`, including custom roles you create.
>
> - Passing the `publicId` of a subscriber (or of an account with no role at all) returns **`404`**, not `403` — that account is simply not part of the staff collection. Manage subscribers under `/admin/subscribers`.
> - `subscriber` is **not an assignable role**: sending it in `roles` returns `422` with a `roles.0` validation error. Subscriber accounts are created by registration, which also creates their subscription — an admin-minted "subscriber" would have none.
> - The rule is symmetric: `/admin/subscribers/{subscriber}` returns `404` for a staff account.
> - Consequence: an existing subscriber cannot be promoted to staff through the API. Create a new staff account instead.

### Roles & permissions (FR-39)

Roles are referenced by **name**, not by id — there is no `publicId` on roles or permissions.

Admins define their own roles here — e.g. a Proofreader who may only work the task board — pick the permissions each grants, and assign staff to them.

**Managing roles**

| Method | Path | Purpose |
|---|---|---|
| GET | `/admin/roles` | Every assignable role, what it grants, and how many users hold it. Builds the role picker. `subscriber` is not listed — it cannot be assigned. |
| POST | `/admin/roles` | Create a role. Body: `name` (unique), `permissions` (array of names; `[]` allowed). Returns `201`. |
| GET | `/admin/roles/{role}` | One role. |
| PUT | `/admin/roles/{role}` | Rename. Body: `name`. |
| DELETE | `/admin/roles/{role}` | Delete a role that no user holds. |
| GET | `/admin/roles/{role}/permissions` | What the role grants. |
| PUT | `/admin/roles/{role}/permissions` | **Replace** what it grants. Body: `permissions` (array of names; `[]` strips it bare). Takes effect immediately for every user holding the role. |
| GET | `/admin/permissions` | Every permission name in the system (the vocabulary for the two lists above). |

`{role}` is the role **name**, URL-encoded — `/admin/roles/Content%20Manager`.

**Assigning to users**

| Method | Path | Purpose |
|---|---|---|
| GET | `/admin/users/{user}/roles` | The user's current roles (returns the full `UserResource`). |
| PUT | `/admin/users/{user}/roles` | **Replace** the user's roles. Body: `roles` (array of names, ≥1). Any role except `subscriber` may be assigned, including ones you just created. |
| GET | `/admin/users/{user}/permissions` | The user's permissions (returns the full `UserResource`). |
| PUT | `/admin/users/{user}/permissions` | **Replace** the user's direct permissions — extras on top of their roles. Body: `permissions` (array of names; `[]` clears them). |

Every `PUT` replaces wholesale rather than adding — send the complete desired list, not a delta.

**`GET /admin/roles` response:**
```json
{
  "data": [
    { "name": "Proofreader", "isSystem": false, "usersCount": 3, "permissions": ["access admin portal", "proofread products"] },
    { "name": "admin", "isSystem": true, "usersCount": 2, "permissions": ["access admin portal", "manage users", "manage subscribers", "view audit logs", "view analytics", "manage vault", "manage topics", "proofread products"] },
    { "name": "System Admin", "isSystem": true, "usersCount": 1, "permissions": [] }
  ]
}
```
- `isSystem` — a built-in role. **Hide the rename, edit-permissions and delete controls for these**; the API rejects those with `protected_role`. The three built-ins are `admin`, `System Admin` and `subscriber`.
- `usersCount` — how many users hold it. Use it to warn before deleting; the API refuses while it is above zero.
- `System Admin` shows an empty permission list by design: it bypasses every gate rather than holding individual permissions, so treat it as "everything".

**Errors across these endpoints:**

| Status | `code` | Meaning |
|---|---|---|
| 422 | `protected_role` | Tried to rename, re-permission or delete a built-in role (`admin`, `System Admin`, `subscriber`). Create a new role instead. |
| 422 | `role_in_use` | Tried to delete a role users still hold. `meta.usersCount` says how many; move them first. |
| 422 | `self_access_change` | You tried to change your own roles/permissions. Another admin must do it — this prevents self-lockout and self-promotion. |
| 403 | `role_escalation` | Only a `System Admin` may grant or revoke the `System Admin` role, or modify a user who already holds it. |
| 422 | `last_system_admin` | Refused: this would remove the only remaining `System Admin`. Promote someone else first. |
| 422 | validation | Unknown or duplicate role name, or an unknown permission name. |

### Subscribers (FR-41)

| Method | Path | Purpose |
|---|---|---|
| GET | `/admin/subscribers` | List subscribers. Paginated. Query: `search`, `status`, `tier` (tier name). |
| GET | `/admin/subscribers/{subscriber}` | One subscriber (with subscription history). |
| PATCH | `/admin/subscribers/{subscriber}` | Edit / suspend. Body: `first_name`, `last_name`, `phone`, `country`, `status` — all optional. Set `status: "suspended"` to block login. |

### Audit log (FR-40)
`GET /admin/audit-logs` — paginated. Query: `description` (substring), `from`, `to` (dates).
```json
{
  "data": [ { "id": 12, "description": "product approved and published", "subjectType": "GenerationTask", "causer": "System Admin", "properties": {}, "createdAt": "2026-07-15 12:11:57" } ],
  "meta": { "currentPage": 1, "lastPage": 1, "total": 12 }
}
```

### LLM providers — Component→LLM assignment map (FR-20, §13.1)
`GET /admin/llm-providers` — lists the six LLM providers, each with the Components it is permanently
assigned to generate. Use this to show admins which model produces which component's content (e.g. in
the Product Generation Master, where the LLM is derived from the chosen component).

**Response (`200`):**
```json
{
  "data": [
    {
      "publicId": "…", "name": "Claude 3.5 Sonnet", "vendor": "Anthropic",
      "modelId": "claude-3-5-sonnet", "isActive": true,
      "components": [
        { "publicId": "…", "name": "SGA Analytics Abstract Papers (AP)", "code": "A1", "batch": 1, "isTransactional": false, "sortOrder": 1 },
        { "publicId": "…", "name": "Strategic Analytics Research Papers (RP)", "code": "A7", "batch": 3, "isTransactional": false, "sortOrder": 7 }
      ]
    }
  ]
}
```
The binding is one component → exactly one LLM (a component appears under a single provider). The
reverse — which LLM a component uses — is read by finding the provider whose `components` contains it.

### Analytics (FR-42, §14.8)

- `GET /admin/analytics/summary`
  ```json
  { "data": { "totalSubscribers": 42, "activeSubscriptions": 37, "productsPublished": 118, "pendingProofreading": 5 } }
  ```
- `GET /admin/analytics/subscriptions-by-tier`
  ```json
  { "data": [ { "tier": "Freemium", "activeSubscriptions": 20 }, { "tier": "Premium", "activeSubscriptions": 12 } ] }
  ```
- `GET /admin/analytics/products-by-component`
  ```json
  { "data": [ { "component": "Daily Strategic Intelligence Analytics Brief (DB)", "code": "A4", "productsPublished": 14 } ] }
  ```

### Vault — full content repository (FR-43, §14.11)
Drill-down that includes hidden and unpublished products (admins see everything).

| Method | Path | Purpose |
|---|---|---|
| GET | `/admin/vault/components` | All 9 components, each with `productsCount`. |
| GET | `/admin/vault/components/{component}/products` | All products of a component (paginated). Adds `meta.statuses`: a map of each product `publicId` → `{ status, isHidden }`. |
| POST | `/admin/products/{product}/hide` | Immediately hide a product from subscribers. |
| POST | `/admin/products/{product}/unhide` | Restore visibility. |

Hide/unhide return the product's `ProductListResource`. Hiding removes it from every subscriber and public endpoint at the data layer.

> **Three different product counts, deliberately.** Vault `productsCount` counts *every* product — drafts, awaiting-proofreading, awaiting-redaction, approved-but-unreleased, rejected and hidden included — matching the rows the vault itself lists. The public catalogue's `productsCount` counts only published, non-hidden ones, so **the two legitimately differ for the same component**. A third, `analytics/products-by-component` → `productsPublished`, counts published products *including* hidden ones (hiding is a subscriber-facing control, not a measure of editorial output). If you display more than one, label them.

### Task Board — proofreading workflow (FR-44/45, §18.4)

Review is now **two stages**: the abstract + document, then the redacted document. The status flow is

```
awaiting_proofreading → in_proofreading → awaiting_redaction → approved → published
                             ↓                    ↓
                          rejected ────────→ in_proofreading
```

| Method | Path | Purpose |
|---|---|---|
| GET | `/admin/tasks` | Board of generation tasks. Paginated. Query: `status` (TaskStatus). **Card view** — each task's product is a short preview only. |
| GET | `/admin/tasks/{task}` | **Full task detail for review** — the complete product body, the redacted body, the originating topic (with its prompt + QA prompt), and the QA result. Read-only: does **not** change the task status. |
| POST | `/admin/tasks/{task}/open` | Start proofreading — captures proofreader + timestamp, returns the full detail. → `in_proofreading` |
| POST | `/admin/tasks/{task}/proofread` | **Stage 1.** Submit the corrected abstract and document. **Body:** `abstract` (required, ≤5000), `body` (required). → `awaiting_redaction` |
| POST | `/admin/tasks/{task}/redact` | **Stage 2.** Submit the redacted document. **Body:** `redacted_body` (required). Approves the product. → `approved` |
| POST | `/admin/tasks/{task}/approve` | Approve without a redaction pass. → `approved` |
| POST | `/admin/tasks/{task}/reject` | Reject. **Body:** `note` (required, ≤2000). Returns the task to the board. |

`{task}` = task `publicId`. An out-of-order transition (e.g. `/redact` before `/proofread`) returns
`409` with `"code": "invalid_task_transition"`.

> **`abstract` is mandatory and is written here.** The LLM never produces one, so a generated product
> has `"abstract": null` until stage 1 is submitted — and a product without an abstract will never be
> released. Prefill the editor with the abstract field empty; do not paste the body into it.

> **Approving does not publish.** The product becomes `approved` and joins the FIFO release queue;
> a scheduled job takes it live. See [§12](#12-the-content-lifecycle-how-products-appear).

**Board list item (`GET /admin/tasks`)** — lightweight card; the product is a preview (no body):
```json
{ "publicId": "…", "status": "awaiting_proofreading", "queuedAt": "…",
  "proofreadAt": null, "redactedAt": null,
  "topic": { "title": "…", "component": { "code": "A4", "name": "…" } },
  "product": { "publicId": "…", "code": "SGA.A4.2026-08.017", "title": "…", "abstract": null, "locked": true } }
```

**Full detail (`GET /admin/tasks/{task}`, and the open/approve/reject responses)** — includes the
complete article `body` so the proofreader can review everything:
```json
{
  "data": {
    "publicId": "…", "status": "in_proofreading", "attempts": 1, "lastError": null,
    "qaResult": "…QA prompt output…", "rejectionNote": null,
    "proofreader": { "publicId": "…", "name": "System Admin" },
    "proofreadAt": "2026-07-15 12:11:57",
    "redactor": null, "redactedAt": null,
    "queuedAt": "…", "completedAt": null,
    "llmProvider": { "publicId": "…", "name": "Claude 3.5 Sonnet", "vendor": "Anthropic" },
    "topic": { "publicId": "…", "title": "…", "frequency": "weekly", "promptText": "…", "qaPromptText": "…", "component": { "code": "A4", "name": "…" } },
    "product": {
      "publicId": "…", "code": "SGA.A4.2026-08.017", "title": "…",
      "abstract": null, "body": "…the full article text…",
      "redactedBody": null, "redactionApproved": false,
      "status": "in_proofreading", "isHidden": false, "approvedAt": null,
      "locked": false, "component": { "code": "A4", "name": "…" }
    }
  }
}
```
> This is the only place all three content levels appear together — it is admin-only for that reason.
> Use `GET /admin/tasks/{task}` to let a proofreader read the whole article before claiming it; use `open` when they start proofreading (it records who + when). Both return the full body; the board list does not.

**Errors:** `409 invalid_task_transition` if you call an action that isn't valid from the current status. Valid order: `awaiting_proofreading` → **open** → `in_proofreading` → **proofread** → `awaiting_redaction` → **redact** (or **approve**) → `approved` → *released* → `published`. **reject** is available from `in_proofreading` and `awaiting_redaction`.

### Product Generation Master (FR-46, §14.10)

| Method | Path | Purpose |
|---|---|---|
| GET | `/admin/topics` | List topics. Paginated. |
| POST | `/admin/topics` | Create a topic. |
| POST | `/admin/topics/{topic}/queue` | Kick off generation now (creates a task + enqueues the AI job). |
| GET | `/admin/generation-queue` | Same board as `/admin/tasks` (the generation queue view). |

**POST `/admin/topics` body:**

| Field | Rules |
|---|---|
| `title` | required, string, ≤255 |
| `component` | required, component `publicId` |
| `frequency` | required, one of `daily`/`weekly`/`monthly`/`quarterly` |
| `prompt_text` | required, string — the instruction sent to the LLM |
| `qa_prompt_text` | required, string — the quality-assurance instruction |

Returns a `TopicResource`. `POST …/queue` returns the created `GenerationTaskResource` (status `queued`).

---

## 10. Webhooks

### POST `/webhooks/payments/{gateway}`
Server-to-server: the payment gateway calls this to confirm a payment. **Not a frontend concern** — listed for completeness. `{gateway}` is the gateway key (e.g. `fake` locally). Idempotent. Returns `{ "message": "Callback processed.", "status": "successful" }`.

In local/UAT you can simulate a successful payment yourself — see below.

---

## 11. The payment flow (step by step)

The real gateway (PGW) isn't wired yet; a **fake gateway** runs locally so the whole flow works end-to-end.

**Paid subscription:**
1. `POST /auth/register` with a paid `tier` → you get `data.payment.publicId` and `data.instructions` (also note the payment's `gatewayRef`, returned by the gateway; in the fake driver it's `FAKE-XXXX`).
2. The account is `pending`; the user **cannot log in yet** (`403 payment_pending`).
3. Payment settles when the gateway calls the webhook. **To simulate success locally**, POST to `/webhooks/payments/fake`:
   ```json
   { "gateway_ref": "FAKE-XXXX", "result": "success" }
   ```
   (or `"result": "failed"` to simulate failure).
4. Meanwhile the frontend polls `GET /payments/{payment}/status` until `successful`.
5. On success the user is activated, an invoice is generated, and they can `POST /auth/login`.

**Freemium:** no payment — `POST /auth/register` returns a token and an active account immediately. Skip straight to using it.

> When the real PGW driver lands, only steps 1 and 3 change (real STK push / card checkout instead of the fake webhook). The register → poll → login shape stays identical, so build against it now.

---

## 12. The content lifecycle (how products appear)

Understanding this explains why a product may not be visible yet:

```
Admin creates a Topic (for one Component)
        │  POST /admin/topics/{topic}/queue
        ▼
Generation task: queued → generating → qa_running → awaiting_proofreading
        │  (the component's assigned LLM writes the body, then a QA pass runs —
        │   automatic, background. No abstract is produced.)
        ▼
Admin Task Board (awaiting_proofreading)
        │  POST /admin/tasks/{task}/open       → in_proofreading  (captures who + when)
        │  POST /admin/tasks/{task}/proofread  → awaiting_redaction
        │        └─ the ABSTRACT is written here, with the corrected document
        │  POST /admin/tasks/{task}/redact     → approved
        │        └─ the redacted document, reviewed separately
        ▼
Product status = approved  →  in the FIFO release queue. NOT yet visible.
        │  scheduled job `products:release`, hourly, oldest approval first
        ▼
Product status = published  →  now visible in the catalogue,
                               previews, and to entitled subscribers.
```

Two things commonly explain "why isn't my product showing":

1. **It is `approved`, not `published`.** Approval ends editorial review; publication happens on the
   next scheduled release. Releases run in **first-in-first-out order of approval** and take a fixed
   batch each run (`PUBLISHING_RELEASE_BATCH`, default 5), so a just-approved product may wait for
   several runs behind older ones. The vault shows it immediately; the catalogue does not.
2. **It has no abstract.** A product with `"abstract": null` is skipped by the release job entirely,
   however long it waits. Submit stage 1 (`/proofread`) to give it one.

Rejected tasks (`POST …/reject`) go back to the board and the product stays unpublished.

---

## 13. Appendix: subscription tier matrix

Per-component monthly access, seeded from the blueprint's Annex 3 and trimmed to the nine surviving components. `∞` = unlimited, a number = metered monthly limit, `—` = not included (denied).

A `—` no longer means the subscriber sees nothing: where the product has an **approved redaction**, they get the redacted document instead of the abstract. See [the content ladder](#the-content-ladder).

| Component | Freemium | Premium | Superior | Platinum |
|---|---|---|---|---|
| A1 | 10 | ∞ | ∞ | ∞ |
| A2 | 3 | ∞ | ∞ | ∞ |
| A3 | 2 | 5 | ∞ | ∞ |
| A4 | — | ∞ | ∞ | ∞ |
| A5 | — | ∞ | ∞ | ∞ |
| A6 | — | — | ∞ | ∞ |
| A7 | — | — | ∞ | ∞ |
| A8 | — | 2 | 10 | ∞ |
| A9 | — | 2 | 10 | ∞ |

> Prices are placeholders except Freemium (0) — final pricing is client-supplied. Fetch live values from `GET /tiers`; don't hardcode them.

---

*Generated from the live route definitions, FormRequest validation rules, and API Resource shapes. If an endpoint's behaviour ever seems to differ from this document, the code is the source of truth — regenerate the interactive docs with `php artisan scribe:generate` and cross-check.*
