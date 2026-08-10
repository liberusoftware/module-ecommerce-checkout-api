# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this package
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-10

First release. An HTTP presentation for `liberusoftware/ecommerce-checkout`,
whose reason for existing is that the domain's idempotency contract reaches a
client unchanged rather than being reinvented on top of it.

### Added

- A `shopper` route group addressed by the session token: start a session from
  lines handed in, read it in any state, set contact and addresses, apply the
  basket discount, record consent, record a tender, place, and abandon. The
  group needs no actor — the token is the credential and it names exactly one
  checkout.
- A `staff` route group: the open sessions in the actor's team, and one session
  by token in any state, behind the domain's `CheckoutSessionPolicy`. **Read
  only**, deliberately: the policy denies `create` and `delete` outright, and
  every write worth having is on the shopper's surface with the shopper's
  consent behind it.
- **The idempotency contract on `POST /checkout/sessions/{token}/placement`.**
  `Idempotency-Key` is a required request header — a server-minted key would be
  a new key on every retry, and a request without one answers 400. A new key
  answers 200 with `Idempotency-Replayed: false` and dispatches
  `CheckoutCompleted`; a repeat with the same payload answers 200 with
  `Idempotency-Replayed: true`, runs no work and dispatches nothing; a key
  spent on a different payload answers 409; and a key claimed by a call that has
  not finished answers **423** with `Retry-After`. Both successes are 200 so a
  client never has to branch on a status code to learn whether its order exists,
  and 409 and 423 are separated because they are opposite instructions.
- A placement refused by its guards does **not** burn its key: the domain
  releases the claim on a throw, so the retry that fixes the address uses the
  same key. Documented on the operation and pinned by a test.
- Domain refusals mapped to status codes in the base controller's
  `callAction()`, never in middleware: `CheckoutNotOpen` to 409;
  `CheckoutNotPlaceable`, `TenderMismatch`, `TaxAmountNotReallocatable`,
  `DiscountExceedsSubtotal`, `CurrencyMismatch` and `InvalidMoney` to 422.
  `TaxAmountNotReallocatable` in particular is a legitimate 4xx and not a fault.
- Money in every response as `{"minor", "currency", "exponent", "decimal"}` with
  `decimal` a string, carrying the session's own exponent rather than assuming
  two.
- **Redaction of three things.** Consent evidence — the IP address and user
  agent recorded with an agreement — is captured and returned by no operation
  and written to no log line. The session token and the `Idempotency-Key` are
  kept out of the log, which records the route's *pattern* rather than the URL
  as it arrived. And no row id is published on any surface.
- Tenancy and identity — `team_id`, `store_id`, `customer_id` — read from
  request attributes the host's middleware sets or from configuration, and never
  from a request body, because the shopper group is the one an anonymous caller
  can reach.
- Per-group route opt-in via `checkout-api.groups`, empty by default, so
  installing the package publishes no API and enabling the module publishes none
  of it.
- Separate middleware lists for the shopper and staff groups, because a shopper
  is not an actor.
- Per-operation token scopes — `checkout:shopper.read`,
  `checkout:shopper.write`, `checkout:staff.read` — derived from the route group
  and the method's safety and enforced against the actor's own `tokenCan()`.
  There is no `checkout:staff.write`, because there is no staff write.
- Rate limiting on every group through a named limiter the deployment
  configures, per actor where there is one and per address where there is not.
  The package registers its limiter only if nothing else has claimed the name.
- One structured log line per operation — request id, actor, operation, method,
  route pattern, status, outcome — through the host's own logger, refusals and
  anonymous requests included. `X-Request-Id` is echoed on every response.
- An OpenAPI 3.1 fragment at `resources/openapi/checkout.json` covering all ten
  operations, with stable operation ids, schemas, security scopes, error
  responses, pagination parameters, rate-limit headers, the full idempotency
  contract and deprecation metadata. Tests hold it against the registered routes
  in both directions, hold each operation's advertised scope against the one the
  middleware enforces, hold `x-liberu-anonymous` against which group the
  operation is in, and hold the placement operation against every status this
  transport can answer for it.
- `README.md`, `docs/domain.md`, `docs/adoption.md` and `docs/runbook.md`.

### Known limits

- **`IdempotencyConflict` is one class for two conditions.** The controller tells
  a payload conflict from an in-flight claim by rebuilding the in-flight message
  from the domain's own factory with this request's scope and key. That is exact
  rather than a substring guess and it moves with the domain, and
  `IdempotencyTest` pins both factories so a release that splits the class fails
  the suite rather than a client. Marked `ponytail:` in the source; the upgrade
  path is a subclass or an error code in the domain.
- **`GET /staff/checkout/sessions` lists open sessions only.**
  `CheckoutSessionQuery` publishes `open()`, `stale()` and `expired()` and no
  general listing, and a `where` written here would be this package's second
  answer to what a staff member may see. A finished session is reached by its
  token.
- **A session filed under no team is visible to no staff actor**, because
  `CheckoutSessionPolicy` matches on `team_id`. A deployment that wants the staff
  group has to give this API a team — `docs/adoption.md` says so twice.
- **Only placement is idempotent.** A duplicate tender is caught at placement by
  the exact-sum rule, and a duplicate session that is never placed is closed by
  the abandonment sweep. A second idempotency contract would be a second thing
  to get wrong.
- **A stranded claim needs an operator.** The domain releases a claim when the
  work throws, but not when the process is killed mid-request. `docs/runbook.md`
  has the query, and it checks `result IS NULL` for a reason.

[0.1.0]: https://github.com/liberusoftware/module-ecommerce-checkout-api/releases/tag/0.1.0
