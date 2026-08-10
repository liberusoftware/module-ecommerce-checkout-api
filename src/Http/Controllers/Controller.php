<?php

namespace Liberu\Ecommerce\Checkout\Api\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Liberu\Ecommerce\Checkout\Actions\PlaceCheckout;
use Liberu\Ecommerce\Checkout\Exceptions\CheckoutNotOpen;
use Liberu\Ecommerce\Checkout\Exceptions\CheckoutNotPlaceable;
use Liberu\Ecommerce\Checkout\Exceptions\CurrencyMismatch;
use Liberu\Ecommerce\Checkout\Exceptions\DiscountExceedsSubtotal;
use Liberu\Ecommerce\Checkout\Exceptions\IdempotencyConflict;
use Liberu\Ecommerce\Checkout\Exceptions\InvalidMoney;
use Liberu\Ecommerce\Checkout\Exceptions\TaxAmountNotReallocatable;
use Liberu\Ecommerce\Checkout\Exceptions\TenderMismatch;

/**
 * The few things every endpoint in this package does the same way.
 *
 * Deliberately not a place for anything else. A base controller is where
 * business rules go to hide from the tests that should have found them, and
 * every rule here belongs to the domain module — this one only knows how to
 * find the actor, read a page size, shape a response and turn the domain's
 * refusals into status codes.
 *
 * In particular it decides nothing about money, nothing about tax and nothing
 * about whether a checkout may be placed. Those have exactly one implementation
 * each, in `Totals`, in the line's own rate, and in `PlaceCheckout::guard()`.
 */
abstract class Controller extends BaseController
{
    /**
     * The domain's refusals, as status codes.
     *
     * Here rather than in middleware, which is where this was first written in
     * the fleet and where it silently did nothing: `Illuminate\Routing\Pipeline`
     * catches a throwable from the route itself and renders it through the
     * application's exception handler *before* the surrounding middleware sees
     * it, so a middleware `try` around `$next($request)` never fires for
     * anything the controller throws. An uncaught `RuntimeException` is then a
     * 500 — an alert, a log line and a pager, for a request that was only
     * asking for something the rules do not allow.
     *
     * Here rather than in the host's exception handler because the mapping is
     * this transport's opinion and only this transport's: a queue worker seeing
     * `TenderMismatch` should fail the job, not answer 422. A `renderable()`
     * callback registered from a provider cannot tell the difference.
     *
     * Three tiers, and the boundaries between them are the useful part:
     *
     * - **409** — the request is fine and the *resource* is in a state that
     *   refuses it. A session that has already been placed or abandoned; an
     *   idempotency key already spent on a different payload. Retrying this
     *   unchanged will always fail, and no edit to the body fixes it.
     * - **423** — the idempotency key is claimed by a call that has not
     *   finished. Retryable, in a moment, unchanged. Its own status because
     *   answering 409 would tell a client to give up on a request that is at
     *   that instant succeeding somewhere else.
     * - **422** — the request is well-formed and the rules say no. Wrong money,
     *   wrong currency, nothing to place, nobody to tell, a discount that cannot
     *   be reallocated. The caller changes something and tries again.
     *
     * @param  string  $method
     * @param  array<string, mixed>  $parameters
     * @return mixed
     */
    public function callAction($method, $parameters)
    {
        try {
            return parent::callAction($method, $parameters);
        } catch (IdempotencyConflict $e) {
            return $this->idempotencyConflict($e);
        } catch (CheckoutNotOpen $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (CheckoutNotPlaceable|CurrencyMismatch|DiscountExceedsSubtotal|InvalidMoney|TaxAmountNotReallocatable|TenderMismatch $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Two conditions, one exception class, two very different instructions.
     *
     * The domain raises `IdempotencyConflict` both for a key spent on a
     * different payload — a client bug, permanent — and for a key whose first
     * call is still running, which is transient and resolves on its own. Giving
     * both the same status would be the worst answer available: a client told
     * `409` for an in-flight commit stops retrying, and the response it was
     * waiting for never arrives.
     *
     * The two are told apart by rebuilding the in-flight message from the same
     * factory the domain used, with the scope and key this request carried.
     * That is exact rather than a substring guess, and it moves with the domain:
     * if the wording changes, both sides change together. `IdempotencyTest`
     * pins both factories against this method so a domain release that split the
     * class fails here rather than in production.
     *
     * ponytail: message equality, because the domain publishes one class and no
     * code for two conditions. Upgrade path is a subclass or a code in the
     * domain — then this method is three lines shorter.
     */
    private function idempotencyConflict(IdempotencyConflict $e): JsonResponse
    {
        $key = trim((string) request()->header('Idempotency-Key'));

        if ($e->getMessage() === IdempotencyConflict::inFlight(PlaceCheckout::SCOPE, $key)->getMessage()) {
            // Retry-After in seconds. A first call that has claimed the key is
            // inside one database transaction; if it is still going a second
            // later, something is wrong that a faster retry will not fix.
            return response()->json(['message' => $e->getMessage()], 423)->header('Retry-After', '1');
        }

        return response()->json(['message' => $e->getMessage()], 409);
    }

    /**
     * The authenticated actor, or 401.
     *
     * Enforced here rather than left to configured middleware, so a host that
     * publishes the config and empties `middleware` gets an unauthenticated 401
     * instead of an unauthenticated list of every open basket in the business.
     *
     * The shopper controller never calls this, and that is the whole difference
     * between the two audiences: a guest is not an actor, and their credential
     * is the token that names one checkout and nothing else.
     */
    protected function actor(Request $request): Authenticatable
    {
        $actor = $request->user();

        if (! $actor instanceof Authenticatable) {
            abort(401, 'Unauthenticated.');
        }

        return $actor;
    }

    /**
     * The team the actor is working in, which is the tenant the policy scopes
     * to. Null when they are in none, and the policy denies everything in that
     * case, so no read ever falls back to "all teams".
     */
    protected function teamOf(Authenticatable $actor): ?int
    {
        $teamId = data_get($actor, 'current_team_id');

        return $teamId === null ? null : (int) $teamId;
    }

    /**
     * A caller-chosen page size, bounded so one request cannot ask for every
     * open checkout in the business — each of which eager loads three
     * relations.
     */
    protected function perPage(Request $request): int
    {
        $max = (int) config('checkout-api.pagination.max_per_page', 100);

        $input = $request->validate(['per_page' => ['integer', 'min:1', 'max:'.$max]]);

        return (int) ($input['per_page'] ?? (int) config('checkout-api.pagination.per_page', 25));
    }

    /**
     * One resource, or 404.
     *
     * A token that names no session and a token that names somebody else's are
     * indistinguishable here, which is the point: the token *is* the
     * credential, so a 403 on an unknown one would confirm that a guessed
     * string was a real checkout.
     */
    protected function payload(mixed $data, int $status = 200): JsonResponse
    {
        if ($data === null) {
            abort(404, 'Not found.');
        }

        return response()->json(['data' => $data], $status);
    }
}
