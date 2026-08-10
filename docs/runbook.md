# Runbook

What goes wrong with this package in production, how to tell, and what to do.

Ordered by how much money it costs: the duplicate charge first, then the checkout
that will not complete, then the leak, then the noise.

---

## A customer was charged twice

**The one that matters.** Symptoms: two orders for one basket; a customer
support ticket quoting one payment and two confirmation emails; two
`CheckoutCompleted` listeners' worth of work for one session.

### Triage

Start from the idempotency table, which is the record of what committed:

```sql
SELECT id, `key`, payload_hash, subject_id, created_at
FROM ecommerce_checkout_idempotency_keys
WHERE scope = 'checkout.place'
ORDER BY created_at DESC
LIMIT 20;
```

Then find the session:

```sql
SELECT id, token, status, grand_total_minor, placed_at
FROM ecommerce_checkout_sessions
WHERE token = '…';
```

Three shapes, three different faults.

**Two key rows with different `key` values and the same `subject_id`.** The
client minted a new key for the retry. This package did exactly what it was
told; the bug is in the client. Confirm it in the access log — two
`placeCheckout` operations, both `200`, both `Idempotency-Replayed: false`.

> The fix is in the client: mint the key when the Place Order button renders and
> keep it across every retry. See `docs/adoption.md`. Until it ships, the domain
> still refuses — the *second* placement of one session answers 409
> `CheckoutNotOpen`, so a genuine double-charge here means two **sessions**, not
> two placements of one. Look for two sessions with the same lines.

**One key row, and it is older than your retention window would have kept.**
The key was pruned while a client still held it. `CHECKOUT_IDEMPOTENCY_RETENTION_DAYS`
is below the client's longest retry window. **Raise it immediately** — the table
is narrow and the cost of keeping a row too long is disk, while the cost of
dropping one too early is this ticket.

**Two sessions, each placed once, each with its own key.** Not an idempotency
failure at all: the shopper started checkout twice. Whether that is a bug is a
question about your front end, not about this package.

### The thing that is *not* the cause

A `423`. That status never commits anything — it is raised before the work runs,
by a claim that has no stored result. If your logs show 423s around the incident,
they are a symptom of the retry storm, not its cause.

---

## Every placement answers 423

Symptoms: `POST .../placement` returns 423 for a key, forever, and retrying
never clears it.

### Triage

```sql
SELECT id, `key`, payload_hash, result, created_at
FROM ecommerce_checkout_idempotency_keys
WHERE scope = 'checkout.place' AND `key` = '…';
```

A row with `result IS NULL` and a `created_at` more than a few seconds old is a
**stranded claim**: a process died between claiming the key and finishing the
work.

The domain releases a claim when the work *throws*. It cannot release one when
the PHP process is killed — an OOM, a `SIGKILL`, a container evicted mid-request.
The transaction rolled back, so nothing committed; the claim row is outside that
transaction, so it survived.

### Fix

The work did not happen. Delete the stranded claim and let the client retry:

```sql
DELETE FROM ecommerce_checkout_idempotency_keys
WHERE scope = 'checkout.place' AND `key` = '…' AND result IS NULL;
```

**Check `result IS NULL` in the `WHERE` clause, every time.** Deleting a
*completed* record is deleting the thing that stops the next retry placing a
second order.

If this happens more than once, find out what is killing the process. A
placement is one transaction over a handful of rows; it should not be near a
memory or time limit.

---

## A checkout will not place

Symptoms: 422 on `POST .../placement`, repeatedly, from a shopper who has paid.

The message says which guard refused. In order:

| Message contains | Meaning | Fix |
| --- | --- | --- |
| `no lines` | Unreachable through this API — sessions cannot be started empty | Look for direct database writes |
| `email address` | No contact | Client skipped `PUT .../contact` |
| `Tenders total …` | The money does not match | Below |
| `cannot become` (409) | The session is closed or expired | Below |

### The tender mismatch

```sql
SELECT kind, status, amount_minor FROM ecommerce_checkout_tenders WHERE checkout_session_id = ?;
SELECT grand_total_minor FROM ecommerce_checkout_sessions WHERE id = ?;
```

Only `authorized` and `captured` count. Three usual causes:

- **The tender is still `pending`.** Your provider callback has not landed, or
  your code recorded it before the provider confirmed. Record what the provider
  actually said.
- **Two tenders, one duplicated.** `POST .../tenders` is not idempotent by
  design, and a retried tender doubles the sum. This is where that shows up, and
  the exact-sum rule catching it is the intended behaviour — void the duplicate
  rather than loosening the rule.
- **The total moved after the tender.** A discount applied between authorising
  and placing. Re-authorise for the new figure.

An **over**-tender refuses as loudly as an under-tender. That is deliberate: a
refund is not something this module can issue, so silently accepting too much
would leave money owed that nothing here can give back.

### The expired snapshot

`PlaceCheckout` asks the clock, not the column, so a session is refused the
moment its window passes whether or not the sweep has run. If shoppers are
hitting this, `CHECKOUT_SESSION_TTL_MINUTES` is shorter than your checkout takes.
Measure the real distribution before raising it — a long TTL is a long window in
which a price you withdrew is still honoured.

---

## A token or an idempotency key appeared in a log

**Treat both as credentials.** A session token is a working key to somebody's
checkout for as long as the session is open.

This package does not write either one. Its log line records the route's
*pattern* — `checkout/sessions/{token}` — and never the URL as it arrived. So if
you are looking at one, it came from somewhere else:

- The host's own access log, which logs full request lines. **This is the usual
  answer.** Configure it to redact the path segment, or accept that your access
  log is credential material and secure it accordingly.
- A `LOG_LEVEL=debug` deployment with Laravel's query log on.
- An APM or error tracker capturing full URLs.
- A `Referer` header leaking the checkout URL to a third-party script. Set
  `Referrer-Policy: same-origin` on your checkout pages.

Rotate by abandoning the affected sessions. There is no other way to invalidate
a token, and a placed session's token is no longer useful for anything but
reading a receipt.

---

## Consent evidence appeared somewhere it should not

`ip_address` and `user_agent` are recorded on `ecommerce_checkout_consents` and
returned by **no** operation in this API and written to **no** log line — there
are tests for both. If they are showing up:

- Check whether something is reading the table directly, which is legitimate
  from a panel and not from a public endpoint.
- Check `resources/openapi/checkout.json` still declares `Consent` with
  `additionalProperties: false`; the suite asserts it.

Deleting the columns is not the answer. They are evidence collected for a stated
purpose, and a consent record that cannot say where the agreement came from is
worth less than one that can.

---

## Every request answers 404

Symptoms: the whole API is missing after a deploy.

In order of likelihood:

```bash
php artisan tinker --execute="dump(config('checkout-api.groups'));"
```

- **Empty.** `CHECKOUT_API_GROUPS` is unset. That is the shipped default, and
  installing this package is not supposed to publish an API.
- **Set, but still 404.** The module is not in `MODULES_ENABLED`, or the domain
  module is not either. Both are needed.
- **Config cached before the env changed.** `php artisan config:clear`.

```bash
php artisan route:list --name=checkout.api
```

If that is empty, the provider never booted. If it lists routes at a path you
did not expect, check `CHECKOUT_API_PREFIX` and `CHECKOUT_API_VERSION`.

---

## Staff see nothing

`GET /staff/checkout/sessions` returns an empty page, or 403.

**403** means `CheckoutSessionPolicy::viewAny()` said no, which means the actor
has no `current_team_id`.

**An empty page with a 200** almost always means the sessions have no `team_id`.
The listing is scoped to the actor's team and the policy matches on it, so a
session filed under nobody is visible to nobody. Set `CHECKOUT_API_TEAM_ID`, or
resolve the team per request — `docs/adoption.md` has both.

**A specific session is missing but exists.** The listing shows **open** sessions
only, because that is the query the domain publishes. Fetch it by token at
`GET /staff/checkout/sessions/{token}`.

---

## The rate limiter is refusing placements

The one refusal worth an alert. A placement retry is supposed to be cheap and
safe, and a limiter that refuses it is a limiter that hides the receipt from a
client that has already paid.

```dotenv
CHECKOUT_API_RATE_LIMIT_PER_MINUTE=60
```

Raise it, or register your own limiter under the configured name — the package
registers its own only if nothing else has claimed it, so
`RateLimiter::for('checkout-api', …)` in your own provider wins.

---

## Reading the log

One structured line per operation, on the host's default channel unless
`CHECKOUT_API_LOG_CHANNEL` says otherwise:

```json
{
  "request_id": "…",
  "actor_id": null,
  "operation": "checkout.api.v1.shopper.placement.store",
  "method": "POST",
  "route": "api/v1/checkout/sessions/{token}/placement",
  "status": 200,
  "outcome": "success"
}
```

Useful queries:

- `operation = "…placement.store" AND status = 423` — stranded claims or a retry
  storm.
- `operation = "…placement.store" AND status = 409` — clients reusing keys.
- `outcome = "failure"` grouped by `operation` — where integrations are wrong.

`request_id` is the caller's `X-Request-Id` when they sent one, and comes back on
every response either way, so a customer reporting a failure can quote it.

There is deliberately no `token`, no `Idempotency-Key`, no IP address and no user
agent in that line.
