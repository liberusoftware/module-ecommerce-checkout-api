# Checkout API — what this package owns

An adapter owns a transport and nothing else. This file is the boundary written
down: what belongs here, what belongs to `liberusoftware/ecommerce-checkout`,
and the several things that belong to neither.

## The one sentence

This package turns HTTP requests into calls on the Checkout module's actions and
queries, turns its read models into JSON, turns its refusals into status codes —
and carries its idempotency contract onto the wire without inventing a second
one.

## What lives here

**Routing and opt-in.** Two groups, `shopper` and `staff`, registered only when
`checkout-api.groups` names them. The shipped default is empty.

**The HTTP shape of idempotency.** The `Idempotency-Key` request header, the
`Idempotency-Replayed` response header, the `replayed` field, and the four
statuses. The *mechanism* is the domain's; what a client sees is this package's.

**Status codes.** In the base controller's `callAction()`. See below for the
whole table.

**Redaction.** Consent evidence, the session token, the idempotency key and row
ids are kept off the wire and out of the log line.

**The trust boundary on tenancy.** `team_id`, `store_id` and `customer_id` are
read from request attributes or configuration, never from a request body.

**Scopes.** `checkout:shopper.read`, `checkout:shopper.write`,
`checkout:staff.read`, derived from the route group and the method's safety and
enforced against the actor's own `tokenCan()`.

**One structured log line per operation**, through the host's own logger.

**An OpenAPI 3.1 fragment**, held against the registered routes by a test.

## What lives in the domain module

Everything that decides anything.

| Question | Who answers it |
| --- | --- |
| What a session's totals are | `Services\Totals` |
| Where a discount lands | `Support\ProRata`, pro-rata, untaxable lines in the denominator |
| What a decimal string is in minor units | `Support\MinorUnits` — string arithmetic, never a float |
| Whether a checkout may be placed | `Actions\PlaceCheckout::guard()` |
| Whether a key is a retry, a conflict or in flight | `Services\Idempotency` |
| Whether a staff actor may see a session | `Policies\CheckoutSessionPolicy` |
| What a session in progress looks like | `Data\CheckoutSessionData` and friends |
| Which sessions are open, stale or expired | `Queries\CheckoutSessionQuery` |

This package holds **no `use Liberu\…\Models\…` anywhere in `src/`**, and the
boundary suite greps for it. Reads go through `Queries\`, come back as `Data\`,
and writes go through `Actions\`. The one place a class name appears at all is a
string constant in `StaffController` for the class-level `viewAny` gate check.

## What belongs to neither

This package does not, and the domain does not either:

- **Create orders.** Subscribe to `CheckoutCompleted`.
- **Validate coupons.** `PUT .../discount` takes an amount you decided.
- **Look up tax rates.** Hand in basis points or an amount, per line.
- **Reserve stock.** Subscribe to `CheckoutStarted` and ask Inventory Ledger.
- **Talk to a payment provider.** Call yours, then `POST .../tenders` what it
  said. There is no provider name anywhere in `src/`.
- **Validate addresses.** They are stored whole and travel intact.
- **Quote shipping.** Add a line of `kind: shipping` at the price you quoted.
- **Read a cart.** Lines are handed in.

## Statuses

Three tiers, and the boundaries between them are the useful part.

**409 — the resource refuses, permanently.** A session that is no longer open;
an idempotency key already spent on a different payload. Retrying unchanged will
always fail and no edit to the body helps.

**423 — the idempotency key is claimed by a call that has not finished.**
Transient, retryable unchanged, and carrying `Retry-After`. Its own status
because answering 409 would tell a client to give up on a request that is at
that instant succeeding somewhere else. Never an empty success.

**422 — well-formed, and the rules say no.** Validation, or one of the domain's
refusals.

| Exception | Status |
| --- | --- |
| `IdempotencyConflict` (different payload) | 409 |
| `IdempotencyConflict::inFlight()` | 423 |
| `CheckoutNotOpen` | 409 |
| `CheckoutNotPlaceable` | 422 |
| `TenderMismatch` | 422 |
| `TaxAmountNotReallocatable` | 422 |
| `DiscountExceedsSubtotal` | 422 |
| `CurrencyMismatch` | 422 |
| `InvalidMoney` | 422 |

`TaxAmountNotReallocatable` deserves a note. It is raised when a discount moves
under a line whose tax arrived as an amount rather than a rate. It is a
legitimate 4xx and not a fault: the request was well-formed, and the module has
promised never to derive a rate from an amount. The fix is upstream — supply
`tax_rate_bp` at session start if a coupon can be entered after tax is computed.

### One exception class, two conditions

The domain publishes `IdempotencyConflict` for both a payload conflict and an
in-flight claim. `Controller::idempotencyConflict()` tells them apart by
rebuilding the in-flight message from the domain's own factory with the scope
and key this request carried — exact rather than a substring guess, and it moves
with the domain if the wording changes. `IdempotencyTest` pins both factories so
a domain release that split the class fails the suite rather than a client.

This is marked `ponytail:` in the source. The upgrade path is a subclass or an
error code in the domain, at which point the method is three lines shorter.

## The two credentials

**The token.** 48 alphanumeric characters minted by `StartCheckout`. It is the
address of the resource and the credential for it at the same time, and the
route constrains the segment to `[A-Za-z0-9]{20,64}` so a scanner walking the
path space never reaches the database.

There is no lookup by id in the domain, deliberately, and this package publishes
no id either. An unknown token and somebody else's token both answer 404 — a 403
would confirm that a guessed string is a real checkout.

**An actor**, for the staff group only, resolved by the base controller itself
so that a host which empties `checkout-api.middleware` gets a 401 rather than an
unauthenticated listing.

## Wire shapes

`Http\Resources\Wire` is the only place a domain read model becomes JSON. Three
differences from the read model's own `toArray()` earn it its file:

1. **Money is an object**, carrying its own currency and exponent, with
   `decimal` as a string.
2. **Consent evidence is dropped** — `ip_address` and `user_agent` never come
   back.
3. **No row ids.**

A placement is a different shape from a session rather than a session with
`status: placed`. A client reading it has finished: there is no `expires_at` left
to watch and no discount left to change, and the two fields it gains —
`idempotency_key` and `placed_at` — are the two a retry needs to recognise its
own receipt.

## Configuration

| Key | Default | Notes |
| --- | --- | --- |
| `checkout-api.groups` | `[]` | Empty means no API. |
| `checkout-api.prefix` / `.version` | `api` / `v1` | Routes and route names both carry the version. |
| `checkout-api.middleware` | `[]` | The staff guard. 401 is answered without it either way. |
| `checkout-api.shopper_middleware` | `[]` | Where a host resolves its tenant. |
| `checkout-api.tenant.team_id` / `.store_id` | `null` | Overridden per request by `checkout.*` attributes. |
| `checkout-api.max_lines` | `200` | One transaction's worth. |
| `checkout-api.rate_limit.*` | `checkout-api`, 60/min | Registered only if nothing claimed the name. |
| `checkout-api.pagination.*` | 25, max 100 | The staff listing only. |
| `checkout-api.log_channel` | `null` | The host's default channel. |

## Deliberate omissions

- **No staff write of any kind.** Read only, as above.
- **No general staff listing.** `CheckoutSessionQuery` publishes `open()`,
  `stale()` and `expired()`; this package writes no `where` of its own, because
  a second answer to what a staff member may see is a bug waiting to happen. A
  finished session is reached by its token.
- **No idempotency on anything but placement.** A duplicate tender is caught at
  placement by the exact-sum rule; a duplicate session that is never placed is
  closed by the abandonment sweep.
- **No expiry endpoint.** `DELETE` closes a session as `abandoned` only. Expiry
  is the merchant's price snapshot running out, which is not something a caller
  may assert; the host's schedule closes those.
- **No `PruneIdempotencyKeys` endpoint.** That is a scheduled command, and an
  HTTP route that deletes the record of every commit is a route nobody wants to
  find in an access log.
