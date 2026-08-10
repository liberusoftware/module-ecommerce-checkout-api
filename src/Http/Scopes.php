<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Checkout\Api\Http;

/**
 * The scopes this package publishes, and the one rule that assigns them.
 *
 * Derived from the route name rather than declared per route, because a
 * per-route argument is a per-route opportunity to write the wrong one — and a
 * scope that does not match the operation it guards is worse than no scope at
 * all. The rule is the whole of it: the route group decides the audience, the
 * HTTP method's safety decides read or write.
 *
 * The audience is the thing being scoped, not the resource. A token that may
 * drive a shopper's own checkout is a categorically smaller grant than one that
 * may list every open session in a team, and a single `checkout:sessions.read`
 * covering both would make the difference invisible to whoever issues the token.
 *
 * Both the middleware that enforces these and the test that checks the OpenAPI
 * document read them here, so a document naming a scope this class does not
 * issue fails the suite rather than a consumer's integration.
 */
final class Scopes
{
    /**
     * Every scope the document is allowed to name.
     *
     * `staff` has no write scope because it has no write route. A scope with
     * nothing behind it is a promise to a token issuer that nothing checks.
     *
     * @var list<string>
     */
    public const ALL = [
        'checkout:shopper.read',
        'checkout:shopper.write',
        'checkout:staff.read',
    ];

    /**
     * The scope an operation requires, or null when the route is not this
     * package's.
     *
     * Matched on the group segment rather than on position, so the configurable
     * version segment — which a host may set to anything, including something
     * with a dot in it — cannot shift the answer. Neither group name can appear
     * anywhere else in a route name: the prefix is `checkout.api.` and the
     * resource segments are `sessions`, `consents`, `tenders` and `placement`.
     */
    public static function forRoute(string $routeName, string $method): ?string
    {
        if (preg_match('/\.(shopper|staff)\./', $routeName, $matches) !== 1) {
            return null;
        }

        $safe = in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);

        return 'checkout:'.$matches[1].'.'.($safe ? 'read' : 'write');
    }
}
