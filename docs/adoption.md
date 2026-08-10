# Adopting `liberusoftware/ecommerce-checkout-api`

## Install

The domain module this presents is tagged on GitHub but not yet on Packagist, so
**your application's own `composer.json`** needs a VCS repository entry for it.
Composer honours `repositories` only from the root manifest, so the entry this
package carries helps its own CI and not you:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-checkout" },
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-checkout-api" }
]
```

```bash
composer require liberusoftware/ecommerce-checkout-api
```

Installing boots nothing. Neither package ships `extra.laravel.providers`, so
`ModuleManagerServiceProvider` is the only thing that registers them, and only
when the deployment names them:

```dotenv
MODULES_ENABLED=ecommerce-checkout,ecommerce-checkout-api
```

**Enabling still publishes no API.** An API that accepts a payment instruction is
a commitment, and a package that starts accepting one because Composer ran has
made that commitment on your behalf. Name the groups you want:

```dotenv
CHECKOUT_API_GROUPS=shopper
```

Recognised groups are `shopper` and `staff`. A group you do not name is not
registered — its paths answer 404, not 403, so there is no policy to get wrong
and no controller to reach.

## The five things you must do

### 1. Guard the staff group

```bash
php artisan vendor:publish --tag=checkout-api-config
```

```php
// config/checkout-api.php
'middleware' => ['api', 'auth:sanctum'],
```

The staff endpoints answer 401 without an actor whether or not you fill this in —
they resolve the actor themselves — so this is how you say *which* mechanism,
not whether. Authorization is entirely `CheckoutSessionPolicy`, which the domain
module registers.

### 2. Decide what the shopper group runs through

```php
'shopper_middleware' => ['api'],
```

A shopper is not an actor and this group needs no authentication to be safe: the
48-character session token is the credential and it names exactly one checkout.
Add a guard if your checkout is behind one — a B2B portal, a staging site.

### 3. Resolve your own tenant

**`team_id`, `store_id` and `customer_id` are never read from a request body.**
The shopper group is the one an anonymous caller can reach, and an anonymous
caller asserting `"customer_id": 42` is filing an order against somebody else's
account.

For a single-tenant shop, configuration is enough:

```dotenv
CHECKOUT_API_TEAM_ID=1
CHECKOUT_API_STORE_ID=1
```

For anything multi-brand or multi-team, resolve them per request in middleware
you add to `shopper_middleware`:

```php
public function handle(Request $request, Closure $next)
{
    $store = Store::forHost($request->getHost());

    $request->attributes->set('checkout.team_id', $store->team_id);
    $request->attributes->set('checkout.store_id', $store->id);
    $request->attributes->set('checkout.customer_id', $request->user()?->id);

    return $next($request);
}
```

An attribute wins over the configured default.

**A session filed under no team is visible to no staff actor at all**, because
the policy matches on `team_id`. If you want the staff group, give this API a
team.

### 4. Subscribe to `CheckoutCompleted`

Without this, a shopper pays and nothing happens. This package does not create
orders and neither does the domain module.

```php
Event::listen(CheckoutCompleted::class, CreateOrderFromCheckout::class);
```

Make your listener idempotent on `$event->checkout->idempotencyKey`. The domain
guarantees one *dispatch*; a queued listener can still be redelivered.

That key is the same string the client sent in the `Idempotency-Key` header and
the same one that comes back in the placement response as `idempotency_key`, so
it is the correlation id across the whole chain.

### 5. Schedule the two sweeps

Both belong to the domain module and neither runs on its own — a module that
registers a scheduled task on install has decided when your database gets
written to. See that package's `docs/adoption.md`; the one this API depends on
most is `PruneIdempotencyKeys`.

## Teaching your client the idempotency contract

This is the integration work that actually matters, and it is mostly on the
client side.

**Mint the key when you render the button, not when you send the request.**

```js
// When the Place Order button first renders:
const idempotencyKey = crypto.randomUUID();

// Every attempt — the first, and every retry after a timeout — sends the same one.
await fetch(`/api/v1/checkout/sessions/${token}/placement`, {
  method: 'POST',
  headers: { 'Idempotency-Key': idempotencyKey },
});
```

A key regenerated on each click protects nothing. That is the single most
common way this feature is adopted and gets no benefit from it.

**Handle four answers:**

| Status | What your client does |
| --- | --- |
| `200` | Done. Read `replayed` if you care whether this call did the work; the order exists either way. |
| `400` | You did not send a key. A bug in your client, not a user-facing error. |
| `409` | You reused a key across two different checkouts. A bug in your client. Do **not** retry. |
| `422` | The checkout is not placeable yet. Show the message, let the user fix it, retry **with the same key**. |
| `423` | A first attempt is still running. Back off for `Retry-After` and retry unchanged. |

**Do not treat 422 as the end of a key's life.** The domain releases the claim
when the work throws, so the key is still good and reusing it is what keeps the
retry safe.

**Set your retention window against your longest retry window.**

```dotenv
CHECKOUT_IDEMPOTENCY_RETENTION_DAYS=30
```

That is the domain module's setting and it is a **safety** setting. A key pruned
while a client still holds it stops being idempotent: the next retry finds no
record, does the work again, and places a second order. A mobile app that queues
a failed request until its next launch has a retry window measured in days.

**Set your rate limit against it too.** A placement retry is supposed to be
cheap and safe, so a limiter tight enough to refuse the retry is a limiter that
hides the receipt.

```dotenv
CHECKOUT_API_RATE_LIMIT_PER_MINUTE=60
```

## Mapping your basket onto `POST /checkout/sessions`

This is the whole integration on the way in, and it is deliberately yours: the
mapping is where your own decisions about what a line *is* live.

```json
{
  "currency": "GBP",
  "email": "shopper@example.com",
  "discount_minor": 500,
  "lines": [
    {
      "name": "Rain Coat",
      "sku": "RC-1",
      "product_id": 12,
      "variant_id": 34,
      "unit_price_minor": 5999,
      "quantity": 2,
      "tax_rate_bp": 2000
    },
    {
      "kind": "shipping",
      "name": "Standard delivery",
      "unit_price_minor": 499,
      "tax_rate_bp": 2000
    }
  ]
}
```

Three things to get right:

**Convert decimal prices with string arithmetic.** `(int) (19.99 * 100)` is
`1998`. The domain publishes `MinorUnits::fromDecimalString('19.99')`.

**Prefer `tax_rate_bp` to `tax_minor`.** Handing in an amount is a one-way door:
`PUT .../discount` then answers 422, because reallocating an amount would mean
deriving a rate from it. If a coupon can be entered *after* tax is computed,
supply rates.

**Shipping is a line, not a field.** That is what gives the discount rule, the
tax rule and the rounding exactly one implementation.

## What this package deliberately does not give you

- **Any staff write.** Read only. Every write is on the shopper's surface with
  the shopper's consent behind it.
- **A general staff listing.** `GET /staff/checkout/sessions` lists **open**
  sessions, because that is the query the domain publishes. A placed or
  abandoned session is reached by its token.
- **The consent evidence.** The IP address and the user agent recorded with a
  consent are in the database and in no response. Reach them from a panel or a
  reporting query, under an actor's authority.
- **Idempotency on anything but placement.**
- **An "expire this session" call.** `DELETE` abandons. Expiry is the merchant's
  clock, and the domain's sweep owns it.

## Serving two versions at once

Routes are registered under `{prefix}/{version}` and named
`checkout.api.{version}.*`. Publish the config twice over rather than renaming
anybody's routes:

```dotenv
CHECKOUT_API_PREFIX=api
CHECKOUT_API_VERSION=v1
```

The OpenAPI document's `servers` entry carries both segments as variables.
