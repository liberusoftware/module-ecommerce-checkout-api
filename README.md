# Ecommerce: Checkout API

> This optional API presentation package exposes approved HTTP operations for the Checkout domain module. It presents exactly one independent module, delegates all authoritative behavior to that module's public actions/queries/policies, and contains no other module's API logic.

[Software](https://liberusoftware.com) ·
[Hosting](https://liberuhosting.com) ·
[Services](https://liberuservices.com) ·
[Liberu Group](https://liberugroup.com)

![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white) ![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
[![Latest release](https://img.shields.io/github/v/release/liberusoftware/module-ecommerce-checkout-api?sort=semver)](https://github.com/liberusoftware/module-ecommerce-checkout-api/releases/latest) [![Tests](https://github.com/liberusoftware/module-ecommerce-checkout-api/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/liberusoftware/module-ecommerce-checkout-api/actions/workflows/tests.yml)

## Features

- Fully compatible with **Laravel 13**, **PHP 8.5**, and **Pest 5**.
- Built following the domain-driven design guidelines of the Liberu architecture.
- Reusable, presenting a clean public contract and boundaries.
- Adheres to the strict database, security, and authorization standards of Liberu.

## Requirements

- **PHP 8.5**
- **Composer 2**
- A supported database (e.g. MySQL, PostgreSQL, SQLite)
- `liberusoftware/ecommerce-checkout` — the domain module this presents

## Idempotency is the point of this package

The domain treats idempotency as a first-class feature. HTTP is where clients
actually meet it: a shopper double-clicking Place Order, a mobile client
retrying a request whose response never arrived, and a proxy replaying a POST
are one event three times, and the difference between one charge and three is
one header and four status codes.

`POST /checkout/sessions/{token}/placement` carries the domain's contract onto
the wire without inventing a second one on top of it.

| Situation | Status | Response |
| --- | --- | --- |
| No `Idempotency-Key` header | **400** | `{"message": …}` |
| New key | **200** | `Idempotency-Replayed: false`, `"replayed": false`. Work ran, `CheckoutCompleted` dispatched. |
| Same key, same payload | **200** | `Idempotency-Replayed: true`, `"replayed": true`. Stored result replayed, **no event**. |
| Same key, different payload | **409** | The key was spent on a different checkout. |
| Key claimed, work unfinished | **423** | `Retry-After: 1`. Retry unchanged. |

Five things about that table are decisions, not defaults:

**The header is required.** A key minted server-side would be a new key on every
retry, which is the same as no key at all — and the whole failure this endpoint
exists to prevent is the retry of a request whose response never arrived. So a
missing header is a 400: a fault in the request itself that no change to the
body can fix, rather than a silent unprotected commit.

**Both successes are 200.** A client must not have to branch on a status code to
find out whether its order exists. The replay flag is in a header *and* in the
body — the header for a live client, the body because a client reading a stored
response has the body and not the headers — and the `data` object is byte for
byte identical either way.

**409 and 423 are different answers to different questions.** The domain raises
one exception class, `IdempotencyConflict`, for both. Collapsing them here would
be the worst available answer: a client told "conflict" for an in-flight commit
stops retrying, and the response it was waiting for never arrives. 409 is
permanent and 423 is "ask again in a moment", and those are opposite
instructions.

**A failed attempt does not burn the key.** The domain releases a claim when the
work throws, so a placement refused by its guards — no email yet, tenders short
of the total, session expired — leaves the key usable. The shopper fixes what
was wrong and retries with the same key, which is what a client naturally does.

**Nothing else here is idempotent, on purpose.** A duplicate tender is caught
where it matters, at placement, by the exact-sum rule. A duplicate session that
is never placed costs a row and is closed by the abandonment sweep. A second
idempotency contract for those would be a second thing to get wrong.

## Two audiences, two credentials

A **shopper** request is addressed by the session's `token` — 48 unguessable
characters, the only handle a guest has on their own checkout, and a bearer
credential in every sense. The group needs no actor, because the token names
exactly one session and nothing else. The domain publishes no lookup by id
precisely so that a URL can never be an enumeration of everybody's baskets.

A **staff** request is addressed by an actor and governed entirely by the
domain's `CheckoutSessionPolicy`. It is **read only**, which is a decision
rather than a gap: the policy denies `create` and `delete` outright, and every
write worth having on a checkout is on the shopper's own surface with the
shopper's own consent behind it. An operator editing a price snapshot somebody
agreed to, over HTTP, is the thing this module copies its lines to prevent.

They are separate route groups, separately opted in, with separate scopes and
separate middleware lists. Neither has a parameter that turns it into the other.

## The operations

Nothing is registered until `checkout-api.groups` names a group. The shipped
default is empty.

### `shopper`

| | |
| --- | --- |
| `POST /checkout/sessions` | Start a session **from lines handed in**. |
| `GET /checkout/sessions/{token}` | Read it, in any state. |
| `PUT /checkout/sessions/{token}/contact` | Email and addresses. Null leaves a field alone. |
| `PUT /checkout/sessions/{token}/discount` | The basket-wide discount, already decided. |
| `POST /checkout/sessions/{token}/consents` | Evidence: what, which version, when, from where. |
| `POST /checkout/sessions/{token}/tenders` | What a provider reported. |
| `POST /checkout/sessions/{token}/placement` | Commit, exactly once. |
| `DELETE /checkout/sessions/{token}` | Abandon. Never "expire". |

### `staff`

| | |
| --- | --- |
| `GET /staff/checkout/sessions` | Open sessions in the actor's team. |
| `GET /staff/checkout/sessions/{token}` | One session, in any state. |

The full contract is `resources/openapi/checkout.json`, an OpenAPI 3.1 fragment
that `tests/Feature/OpenApiDocumentTest.php` holds against the registered routes
in both directions.

## There is no cart, and there cannot be one

`POST /checkout/sessions` takes lines in the body. There is no cart parameter,
no cart lookup, no `carts` table in the test suite and no requirement on
`liberusoftware/ecommerce-cart`.

That is the domain's rule and it is not tidiness. A price a shopper agreed to
must not be able to move between the agreeing and the charging, so the session
copies its lines — and once the copy is forced by the domain, there is nothing
left for a cart dependency to buy. A caller with a cart maps it to lines. A
caller taking a phone order builds the same lines. Neither is privileged.

## Three facts never come from the request body

`team_id`, `store_id` and `customer_id`. They come from request attributes the
host's own middleware sets, falling back to `checkout-api.tenant`:

```php
$request->attributes->set('checkout.team_id', $store->team_id);
$request->attributes->set('checkout.store_id', $store->id);
$request->attributes->set('checkout.customer_id', $request->user()?->id);
```

The shopper group is exactly the group an anonymous caller can reach. A create
that accepts a tenant id is a create that files somebody else's business's
order, and an anonymous caller asserting `"customer_id": 42` is an anonymous
caller filing an order against somebody else's account.

## Money

Every amount in every response is an object:

```json
{ "minor": 1999, "currency": "GBP", "exponent": 2, "decimal": "19.99" }
```

`minor` is the authority — integer minor units, no float anywhere in this fleet.
`exponent` travels with it because a consumer handed `1999` cannot render it
without knowing where the point goes, and that is the knowledge that reliably
goes missing in a client written by somebody else. `decimal` is a **string**,
because a JSON number is a float in most parsers and `19.99` does not exist as
one. (`(int) (19.99 * 100)` is `1998`; there is a test that says so.)

## What is captured and never returned

**Consent evidence.** `POST .../consents` records the request's IP address and
user agent alongside the agreement, because a regulator asks who agreed and from
where. Neither is in any response in this document, and neither is in any log
line. Echoing them onto a surface reachable with a bearer token would turn a
legal record into a disclosure; they stay in the database, where a panel or a
subject-access request reaches them under an actor's authority.

**The token and the idempotency key.** The structured log line records the
route's *pattern* — `checkout/sessions/{token}` — and never the URL as it
arrived. A token in a log aggregator is a working key to somebody's basket, held
by everybody with log access, for the length of the retention policy.

**Row ids.** None are published on any surface. An incrementing id in a response
body is an enumeration one request later. The token is the handle, and for a
placement the idempotency key is the correlation.

## Statuses, and what they mean

| | |
| --- | --- |
| **400** | The request itself is unusable. Only the missing/over-long `Idempotency-Key`. |
| **401** | No actor. Staff group only; answered whether or not the host configured a guard. |
| **403** | The policy said no, or the token is missing the scope. |
| **404** | No such session. Identical for an unknown token and somebody else's. |
| **409** | The resource refuses the request, permanently. Session no longer open; key spent on a different payload. |
| **422** | Well-formed, and the rules say no. Validation, or a domain refusal. |
| **423** | The idempotency key is claimed by an unfinished call. Transient. |
| **429** | The limiter refused. |

The mapping lives in the base controller's `callAction()`, not in middleware.
`Illuminate\Routing\Pipeline` catches a throwable from the route and renders it
through the application's exception handler *before* the surrounding middleware
resumes, so a middleware `try` around `$next($request)` never fires for anything
a controller throws — and an uncaught `RuntimeException` is then a 500, an
alert and a pager, for a request that was only asking for something the rules do
not allow.

It is not in the host's exception handler either, because the mapping is this
transport's opinion and only this transport's: a queue worker seeing
`TenderMismatch` should fail the job, not answer 422.

## Installing

```bash
composer require liberusoftware/ecommerce-checkout-api
```

Installing boots nothing, and enabling the module publishes no API. See
[docs/adoption.md](docs/adoption.md) — including the VCS `repositories` entry
the host needs until these packages are on Packagist.

## Documentation

- [docs/domain.md](docs/domain.md) — what this package owns and what it refuses to
- [docs/adoption.md](docs/adoption.md) — installing it into an application
- [docs/runbook.md](docs/runbook.md) — what goes wrong in production and what to do
- `resources/openapi/checkout.json` — the OpenAPI 3.1 fragment

## License

MIT. See [LICENSE.md](LICENSE.md).
