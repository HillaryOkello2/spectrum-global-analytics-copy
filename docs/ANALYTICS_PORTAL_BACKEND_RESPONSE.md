# Reply to `ANALYTICS_PORTAL_BACKEND_REQUIREMENTS.md`

**Date:** 2026-10-01 · **Backend:** `spectrum-global-analytics` · 327 tests passing.

Thanks — the item-by-item format made this quick to work through. Eight items are built, six were
already there (your reference copy is behind), four need a decision from the client rather than code,
and four were your own adapter notes that needed nothing from us.

**Before anything else:** you cite `API_REFERENCE2.md` / `API_REFERENCE6.md`. Those are stale
snapshots. The source of truth is **`docs/API_REFERENCE.md`** in the backend repo (updated today),
with `docs/FRONTEND_API_GUIDE.md` as the mental model. Copy them in fresh rather than diffing against
the numbered ones. Several asks below were already shipped and the old files simply do not show them.

---

## Built today

| # | Ask | What to call |
|---|---|---|
| 1 | Component aggregate rating | `averageRating` + `ratingsCount` on `GET /catalog/components` and `GET /admin/vault/components` |
| 2 | Ratings + state on the admin list | Vault rows now carry `averageRating`, `ratingsCount`, `status`, `isHidden`, `readsCount` |
| 4 | Reading time | `wordCount` + `readMinutes` on every product shape, listings included |
| 9 | Topic author | `createdBy: { publicId, name }` on `GET`/`POST /admin/topics` |
| 10 | Vault "Views" | `readsCount` per product — it already existed, see below |
| 11 | Admin reading a body | **New** `GET /admin/vault/products/{product}` |
| 12 | `Role.description` | `description` on role payloads, settable on create and update |
| 16 | Subscriber's own stats | **New** `GET /me/stats` |

Details worth reading before you wire them:

**#1 Component rating.** Aggregated in the listing query in one pass — no N+1, and you were right not
to average products client-side. `averageRating` is **absent** when nobody has rated the component
(`ratingsCount: 0`): absent means "no ratings yet", which a `0` would render as half a star. The
public catalogue averages over published, non-hidden products; the vault's spans everything it lists,
so the two differ for the same component on purpose.

**#2.** The public list already had ratings (see below) — the admin list is what was missing them.
Note `locked` is deliberately **not** on vault rows: in the vault nothing is locked, staff see
everything. `status` + `isHidden` are the state you actually want there. `meta.statuses` still ships
unchanged, so nothing breaks if you wire the row fields later.

**#4 Reading time.** Counted server-side when a body is written, so listings and previews — which
carry no body — can still show it. `readMinutes` is 225 words a minute, rounded up, never below 1.
Both keys are absent for a product whose body predates the change until it is next saved; after the
next `migrate:fresh --seed` on the demo box every product has them.

**#10 Views.** This one does exist and always did: there is a `product_reads` ledger and a
denormalised counter behind `analytics/most-read-products`. It was simply never exposed on a product
payload. `readsCount` counts every time a subscriber was served the full or redacted document, repeat
reads included; a locked preview is not a read, and **staff opening the vault is not a read either**.
So no new infrastructure was needed — just the field. The mock's `ratings * 37 + 418` was indeed
invented, but the real number was sitting right there.

**#11 Admin token on `GET /products/{product}`.** Answered definitively: **no, and it never will.**
That route sits behind `role:subscriber`, so a staff token gets a flat 403 — staff hold no
subscription to check. Your fallback branch was firing 100% of the time. Use
`GET /admin/vault/products/{product}`: `body`, `redactedBody`, `redactionApproved`, `status`,
`isHidden`, `readsCount`, `approvedAt`, at any status, no task-board hunt. There is a test asserting
the 403 so nobody "fixes" it into ambiguity later.

**#9 Topic author.** Your description was close but the premise was off: there was no `created_by`
column at all, so nothing was being returned to resolve. There is now, it is populated on create, and
the name is resolved server-side — a topics editor needs no `manage users`. It is **`null` on an auto
topic**, because the scheduler commissioned that edition and no person filed it. Back-filling who
created the existing topics isn't possible; they will read as unattributed.

**#12 Role description.** On `PUT`, omitting `description` leaves the stored one alone — a rename
can't wipe copy the renamer never saw. Send `null` explicitly to clear it. Built-in roles report
`null`.

**#16 Subscriber stats.** `{ articlesRead, reads, ratingsGiven, averageRatingGiven }`.
`articlesRead` is **distinct** products, `reads` counts repeats too — your design wanted the first,
but the second was free and is the more flattering number. `averageRatingGiven` is `null`, not `0`,
for someone who has rated nothing.

---

## Already shipped — your reference is stale

No backend work; these are live now and were before this pass.

- **#3 `byline`** — on `Product`, `ProductPreview` **and** the component product listing. Nothing was
  admin-only about it.
- **#2, public half** — `averageRating`/`ratingsCount` are on `GET /catalog/components/{component}/products`.
  ⚠️ **The key is `ratingsCount`, plural.** Your doc says `ratingCount` in #2 and #21; that spelling
  has never existed and would silently read as `undefined`. Worth grepping your adapter for it.
- **#7 `product.code` on the task list** — `GET /admin/tasks` nests the product preview shape, which
  includes `code`. The board can show the real article code today.
- **#14 Subscriber join date** — `joinedAt` is on every subscriber row (it is the account's
  `created_at`). Your "Subscriber since" relabel was a sound call in the meantime, but you can now
  show both honestly: `joinedAt` for the account, the subscription's `startDate` for the term.
- **#21** — agreed and confirmed.

---

## Needs a client decision, not code

Flagging rather than guessing. Say the word and any of these is a day or less.

- **#5 Region / geography.** Confirmed genuinely absent, and **not derivable**: the prompt variables
  are `PRIMARY_TOPIC`, `BYLINE`, `DOCUMENT_TITLE`, `CORE_THEME_*` — no geography anywhere. If the
  client wants it as a real filterable taxonomy, the clean shape is a `region` on the **Topic** that
  the generated Product inherits, and we need their closed list of regions. If it was only ever
  design garnish, leave it out rather than inventing a taxonomy nobody maintains.
- **#13 Role enable/disable.** Our recommendation: skip it. `DELETE` exists, refuses a role that
  still has holders, and the built-in roles are already protected — "disabled role" adds a third
  state to reason about in every permission check for little gain. Your instinct not to substitute
  delete for the toggle was right.
- **#18 Self-serve cancellation.** `Cancelled` is a real status with no path into it by design. The
  endpoint is trivial; the question is policy: does cancelling end access immediately or run to the
  paid term's end, and is anything refunded? Get that answered and we will add it.
- **#22 Editorial copy.** Your frontend-owned map is the right call for now. If the client wants it
  editable without a deploy, that is a small admin CMS surface — a real feature, worth scoping
  separately.

## Agreed — nothing needed

- **#8, #15** — your adapter fixes. Thanks for writing them down; #15 in particular (two different
  `status` concepts colliding) is the kind of thing that would have cost someone a day.
- **#17 Bills vs the real payment model** — your read is exactly right. There is no unpaid-invoice
  resource because an invoice is only ever raised after a charge succeeds. Invoice history plus the
  real subscription state is the honest screen. **One change since you wrote this:** payments now run
  through PGW (M-Pesa STK push or a hosted card page) and amounts are charged in **KES** —
  `amount`/`currency` is what is charged, `listAmount`/`listCurrency` is the USD tier price. There is
  also a public `POST /payments/{payment}/retry` for a failed payment. See the PGW section of the
  frontend guide.
- **#19 Admin Bills vs Transactions** — agreed, one resource, two framings. Noted on our side too.

---

## Two things back to you

1. **Your doc skips #6 and #20** — the numbering jumps 5 → 7 and 19 → 21. Either two items were
   dropped while editing, or we are missing two asks. Worth a check.
2. **What were you testing against?** Several "the real API returns no X today" items *do* return X
   here. If you were hitting the deployed demo rather than a local checkout, it is behind `main` —
   worth confirming which, because it changes whether the other gaps you find are real or just
   un-deployed.

## Deploy note

The payments, topics and products migrations were edited in place (pre-production convention), so the
demo box needs `php artisan migrate:fresh --seed` rather than an incremental migrate. Reseeding also
gives every product a `wordCount`.
