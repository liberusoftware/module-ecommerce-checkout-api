<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Route groups
    |--------------------------------------------------------------------------
    |
    | Empty by design. Installing an API package must not publish an API — the
    | deployment decides which operations it is prepared to answer for, and a
    | package that exposes everything the moment Composer runs makes that
    | decision on its behalf.
    |
    | Recognised groups:
    |
    |   shopper  the checkout itself, addressed by the session token: start,
    |            read, contact, discount, consent, tender, place, abandon
    |   staff    the operator's read view of open and finished sessions,
    |            behind the domain's `CheckoutSessionPolicy`
    |
    | They are separate groups because they are addressed by different
    | credentials. A shopper holds a token and never touches a gate; staff hold
    | an actor and are governed entirely by the policy. Collapsing them would
    | mean one surface where a missing guard is the difference between a guest
    | seeing their own basket and a guest listing everybody's.
    |
    */

    'groups' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('CHECKOUT_API_GROUPS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Version and prefix
    |--------------------------------------------------------------------------
    |
    | Routes are registered under `{prefix}/{version}` and named
    | `checkout.api.{version}.*`, so a host serving two versions at once
    | publishes this file twice over rather than renaming anybody's routes.
    |
    */

    'prefix' => env('CHECKOUT_API_PREFIX', 'api'),

    'version' => env('CHECKOUT_API_VERSION', 'v1'),

    /*
    |--------------------------------------------------------------------------
    | Middleware — staff
    |--------------------------------------------------------------------------
    |
    | Your guard for the group that needs an actor. Empty by default because
    | this package has no opinion on whether you authenticate with Sanctum,
    | Passport or a header. A typical composition sets `['api', 'auth:sanctum']`.
    |
    | Authentication is still required with none configured — every staff
    | endpoint resolves the actor itself and answers 401 without one. This list
    | is how you say *which* mechanism, not whether.
    |
    */

    'middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | Middleware — shopper
    |--------------------------------------------------------------------------
    |
    | Its own list, because a shopper is not an actor. The session token is the
    | credential: it is 48 unguessable characters, it is the only handle a guest
    | has on their own checkout, and the domain has no lookup by id precisely so
    | that a URL can never be an enumeration of everybody's baskets.
    |
    | Set this to `['api']` for a public checkout. Anything you add here runs
    | before the controller, which is where a deployment resolves its tenant —
    | see `tenant` below.
    |
    */

    'shopper_middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    |
    | Which team and store a newly started session is filed into, and which
    | customer it belongs to.
    |
    | **None of the three is ever read from the request body.** A create that
    | accepts a tenant id is a create that files somebody else's business's
    | order, and an anonymous caller asserting `"customer_id": 42` is an
    | anonymous caller filing an order against somebody else's account. The
    | shopper group is exactly the group an anonymous caller can reach. So the
    | values come from here, or from request attributes your own middleware sets:
    |
    |     $request->attributes->set('checkout.team_id', $store->team_id);
    |     $request->attributes->set('checkout.store_id', $store->id);
    |     $request->attributes->set('checkout.customer_id', $request->user()?->id);
    |
    | An attribute wins over the configured default, so a single-tenant
    | deployment sets team and store once here and a multi-brand one resolves
    | them per host. `customer_id` has no default and cannot sensibly have one —
    | it is per request or it is null.
    |
    | Null throughout is legal and means a guest session belonging to nobody,
    | which is what a small shop with no teams has. **Note what that costs the
    | staff group:** `CheckoutSessionPolicy` matches on `team_id`, so a session
    | with none is visible to no staff actor at all. A deployment that wants the
    | staff surface must set a team.
    |
    */

    'tenant' => [

        'team_id' => env('CHECKOUT_API_TEAM_ID') === null ? null : (int) env('CHECKOUT_API_TEAM_ID'),

        'store_id' => env('CHECKOUT_API_STORE_ID') === null ? null : (int) env('CHECKOUT_API_STORE_ID'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Lines
    |--------------------------------------------------------------------------
    |
    | The ceiling on how many lines one `POST /checkout/sessions` may carry.
    | Starting a session writes a row per line inside one transaction and then
    | recomputes every one of them, so an unbounded list is an unbounded write.
    |
    */

    'max_lines' => (int) env('CHECKOUT_API_MAX_LINES', 200),

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | `limiter` names the limiter applied to every route group. The package
    | registers a per-actor, per-minute limiter under the default name, but only
    | if nothing else has claimed that name — so a deployment with an opinion
    | registers its own `RateLimiter::for('checkout-api', …)` and this one steps
    | aside. Set `limiter` empty to apply none at all.
    |
    | The shopper group is anonymous, so the shipped limiter keys on the actor
    | where there is one and the address where there is not.
    |
    | Retrying a placement is *supposed* to be cheap and safe, so pick a limit
    | that a client backing off through a lost response can live inside. A
    | limiter that refuses the retry is a limiter that hides the receipt.
    |
    | Either way the responses carry `X-RateLimit-Limit` and
    | `X-RateLimit-Remaining`, and a refusal carries `Retry-After` — Laravel's
    | own throttle middleware does that, and nothing here reimplements it.
    |
    */

    'rate_limit' => [

        'limiter' => env('CHECKOUT_API_RATE_LIMITER', 'checkout-api'),

        'per_minute' => (int) env('CHECKOUT_API_RATE_LIMIT_PER_MINUTE', 60),

    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | The staff listing only. `per_page` is a caller-chosen page size and
    | `max_per_page` is the ceiling it is validated against.
    |
    */

    'pagination' => [

        'per_page' => (int) env('CHECKOUT_API_PER_PAGE', 25),

        'max_per_page' => (int) env('CHECKOUT_API_MAX_PER_PAGE', 100),

    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | One structured line per operation — request id, actor, operation, method,
    | route pattern, status, outcome. Null uses the application's default
    | channel, which is almost always what you want: this is the host's log
    | pipeline, not a second one.
    |
    | **Three things are deliberately never written.** The session token, which
    | is a bearer credential and would be a working key to somebody's checkout
    | sitting in a log aggregator; the `Idempotency-Key`, for the same reason;
    | and the IP address and user agent captured on a consent, which are
    | evidence held for one purpose and not telemetry. The logged path is the
    | route's *pattern*, `checkout/sessions/{token}`, never the URL as it
    | arrived.
    |
    */

    'log_channel' => env('CHECKOUT_API_LOG_CHANNEL'),

];
