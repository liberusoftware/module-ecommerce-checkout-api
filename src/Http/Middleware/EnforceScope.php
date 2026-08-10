<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Checkout\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Liberu\Ecommerce\Checkout\Api\Http\Scopes;
use Symfony\Component\HttpFoundation\Response;

/**
 * A token may only do what it was issued for.
 *
 * This is a narrowing, never a widening: the policy check in the staff
 * controller still runs and still decides, and the shopper group is still
 * addressed by a token nobody can guess. A token carrying every scope gets
 * exactly the access its owner has.
 *
 * Enforced against whatever the actor answers to `tokenCan()` — Sanctum's
 * abilities, Passport's scopes, or a host's own implementation. An actor that
 * has no such method carries no scope list to check, which is the case for
 * session-authenticated first-party callers; they are governed by the policy
 * alone, and the OpenAPI document says so.
 *
 * The shopper group usually has no actor at all, and then there is nothing to
 * narrow. That is not a hole: what a shopper request may reach is decided by
 * possession of a 48-character token that names exactly one checkout, and no
 * scope list would make that narrower.
 */
final class EnforceScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $scope = Scopes::forRoute((string) Route::currentRouteName(), $request->method());
        $actor = $request->user();

        if ($scope !== null && $actor !== null && method_exists($actor, 'tokenCan') && ! $actor->tokenCan($scope)) {
            abort(403, 'This token is missing the ['.$scope.'] scope.');
        }

        return $next($request);
    }
}
