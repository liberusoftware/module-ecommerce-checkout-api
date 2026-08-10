<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Checkout\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * One structured line per operation, through the host's own logger.
 *
 * No metrics stack, no storage, no dashboard: a deployment that wants counters
 * derives them from these lines, and one that has a log pipeline already does
 * not want a second one shipped inside a route package.
 *
 * First in the group so that it also sees what the host's guard refuses — an
 * operation rejected at authentication is exactly the one an operator wants in
 * the log — and because `Illuminate\Routing\Pipeline` renders a throwable from
 * anything further in as a response, so `$next()` returns the 401, 403, 409,
 * 422, 423 or 429 rather than throwing past this.
 *
 * **What is deliberately absent is the point of this file.** The logged path is
 * the route's *pattern* and never the URL as it arrived, because the URL carries
 * the session token — a bearer credential, and the only handle a guest has on
 * their own checkout. A token in a log aggregator is a working key to somebody's
 * basket held by everybody with log access, for the length of the retention
 * policy. The `Idempotency-Key` is absent for the same reason, and the consent
 * evidence — the IP address and the user agent — is absent because it was
 * collected for one purpose and a log line is a second one.
 *
 * The request id is the caller's when they sent one, so a line here joins up
 * with the same request in the host's own logs, and comes back on the response
 * either way so a caller reporting a failure can quote it.
 */
final class LogOperation
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = trim((string) $request->header('X-Request-Id')) ?: (string) Str::uuid();

        $response = $next($request);
        $status = $response->getStatusCode();

        $response->headers->set('X-Request-Id', $requestId);

        Log::channel(config('checkout-api.log_channel'))->info('checkout.api', [
            'request_id' => $requestId,
            'actor_id' => $request->user()?->getAuthIdentifier(),
            'operation' => Route::currentRouteName(),
            'method' => $request->method(),
            // The pattern, not the path. `checkout/sessions/{token}`.
            'route' => Route::current()?->uri(),
            'status' => $status,
            'outcome' => $status < 400 ? 'success' : 'failure',
        ]);

        return $response;
    }
}
