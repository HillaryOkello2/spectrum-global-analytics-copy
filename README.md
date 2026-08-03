# Spectrum Global Analytics — Backend API

API-only Laravel backend for the Spectrum Global Analytics subscription intelligence platform
(Blueprint v1.1, Techbiz Limited). Serves the Public Website, Subscriber Portal, and Admin Portal
via a versioned REST API under `/api/v1`. Full architecture:
`~/Spectrum-Backend-Plan/laravel-backend-architecture-plan.md`.

## Stack

Laravel 13 · PHP 8.3 · Sanctum (token auth) · spatie/laravel-permission · spatie/laravel-activitylog
· Horizon (Redis queues, `llm` + `default`) · Pest · Pint. Local dev runs on SQLite + database queue;
staging/production use MySQL 8 + Redis.

## Quickstart

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed   # 12 pillars, 14 components/pillar, 4 tiers + Annex 3 allocations, 6 LLM providers
php artisan serve
./vendor/bin/pest                  # 22 feature tests
```

Seeded local admin: `admin@spectrumglobalanalytics.test` / `password` (System Admin + admin roles).

## API documentation (for the frontend dev)

- **Full offline reference:** [`docs/API_REFERENCE.md`](docs/API_REFERENCE.md) — every endpoint with
  request bodies, validation rules, response examples, enums, error codes, the payment flow, the content
  lifecycle, and the tier matrix. **Works with the server off.** This is the primary hand-off document.
- **Quickstart guide:** [`docs/FRONTEND_API_GUIDE.md`](docs/FRONTEND_API_GUIDE.md) — the same material
  condensed to a one-page mental model.
- **Interactive reference (server up):** `GET /docs` (regenerate with `php artisan scribe:generate`).
- **OpenAPI spec / Postman:** `GET /docs.openapi`, `GET /docs.postman` (also written to
  `storage/app/private/scribe/`). Import into Postman/Insomnia or feed to a client codegen.

Scribe reads request schemas from the FormRequests and URL params from route model binding, so the
reference stays in sync with the code — rerun `scribe:generate` after route/validation changes.

## Layering rules (enforced in review)

```
Route → FormRequest (validation + authorize) → Controller (thin) → Service → Model
```

- Business logic lives in `app/Services/` only; services are HTTP-agnostic and throw
  domain exceptions (`app/Exceptions/Domain/`, rendered to JSON in `bootstrap/app.php`).
- Every input endpoint has a FormRequest (`app/Http/Requests/<Domain>/`).
- API Resources return camelCase keys; models expose UUID `public_id` route keys (`HasPublicId`).
- Statuses are PHP enums (`app/Enums/`) cast on models.

## Key modules

| Area | Entry points |
|---|---|
| Entitlement (tier gating, metering) | `app/Services/Entitlement/EntitlementService.php` |
| Signup / activation (Freemium immediate, paid via gateway) | `app/Services/Billing/SignupService.php`, `SubscriptionActivator.php` |
| Payment gateway contract | `app/Services/Payments/Contracts/PaymentGateway.php` (**PGW driver pending** — `FakeGatewayDriver` for local/UAT; select via `PAYMENT_GATEWAY` env) |
| AI generation pipeline | `app/Services/Generation/GenerationPipeline.php`, jobs in `app/Jobs/` (queue `llm`) |
| LLM drivers (6 providers) | `app/Services/Llm/` — `LLM_FAKE=true` stubs all providers locally |
| Scheduler | `routes/console.php` — daily topic dispatch + `subscriptions:expire` |

## Confirmed business rules

- **Billing period:** monthly; paid subscriptions run a one-month term. Subscribers renew via
  `POST /me/subscription/renew` (Freemium extends instantly; paid goes through the gateway) and
  upgrade via `POST /me/subscription/upgrade` (charged the full new-tier price, fresh month on payment).
- **Freemium:** activates immediately with no payment/gateway call.
- **Metering:** "N products per month" for a component is a **global quota per component code** —
  e.g. Freemium's "A1 max 10/month" covers all 12 pillars' A1 products combined, not 10 per pillar.
- **Component count:** 168 rows = the 14 Annex-2 components repeated under each of the 12 pillars
  (per the blueprint ERD, where COMPONENT has a `pillar_id` FK). FR-04 says "fifteen" while Annex 2
  lists fourteen — the Annex is treated as source of truth; flagged to the client.

## Outstanding

- **PGW payment driver**: implement `PgwGatewayDriver` against `PaymentGateway` once PGW API docs
  are available (existing PGW usage reference: `~/mcp`). Wire it in `PaymentServiceProvider`.
- Pillar→LLM permanent mapping is seeded round-robin pending the client's mapping.
- Tier prices (except Freemium = 0) are placeholders pending client pricing.
- A14 pay-to-own purchase endpoint + transaction-tier pricing (blocked on PGW).
