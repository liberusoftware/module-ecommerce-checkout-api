<?php

namespace Liberu\Ecommerce\Checkout\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Checkout\Api\Http\Resources\Wire;
use Liberu\Ecommerce\Checkout\Data\CheckoutSessionData;
use Liberu\Ecommerce\Checkout\Queries\CheckoutSessionQuery;

/**
 * The operator's read view.
 *
 * **Read only, and that is a decision rather than a gap.** The domain's policy
 * denies `create` and `delete` outright, `update` only holds while a session is
 * open, and every write worth having on a checkout — contact, discount, tender,
 * consent, placement — is a thing the shopper's own surface already does with
 * the shopper's own consent behind it. A staff write endpoint would be an
 * operator editing a price snapshot somebody agreed to, over HTTP, and the
 * module's whole reason for copying its lines is that this must not happen
 * quietly.
 *
 * Authorization is entirely the domain's `CheckoutSessionPolicy`. Nothing here
 * restates a rule it enforces: the team comes off the actor, a session belonging
 * to another team is refused, and an actor in no team is refused everything. An
 * unregistered policy would be an open door — Laravel's unanswered gate case is
 * permissive — so this package leans on the one the domain's provider registers
 * and asserts in `StaffTest` that it is doing the refusing.
 */
class StaffController extends Controller
{
    /**
     * The policy's subject, as a string.
     *
     * An `-api` package may not `use` a `Models\` class — the boundary suite
     * greps for it, and the reason is sound: a transport coupled to somebody
     * else's storage breaks when they rename a column. `Gate::authorize` on an
     * instance needs no class name at all; only the class-level `viewAny` does,
     * and this is the one place it appears.
     */
    private const SESSION = 'Liberu\Ecommerce\Checkout\Models\CheckoutSession';

    public function __construct(private readonly CheckoutSessionQuery $sessions) {}

    /**
     * Open sessions in the actor's team.
     *
     * **Open ones only.** `CheckoutSessionQuery` publishes `open()`, `stale()`
     * and `expired()` and no general listing, and a package that wrote its own
     * `where` here would be a second answer to what a staff member may see. A
     * placed or abandoned session is reachable by its token, which is how an
     * operator following up a specific order gets there.
     *
     * The team is the actor's own and never a parameter. `store_id` narrows
     * within it and cannot widen past it.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('viewAny', self::SESSION);

        $input = $request->validate(['store_id' => ['nullable', 'integer', 'min:1']]);

        $sessions = $this->sessions
            ->open($this->teamOf($actor), isset($input['store_id']) ? (int) $input['store_id'] : null)
            // Eager loaded rather than left off: a listing whose sessions all
            // carry an empty `lines` array is a listing that lies about them.
            ->with(['lines', 'tenders', 'consents'])
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json(
            $sessions->through(fn (object $session): array => Wire::session(CheckoutSessionData::from($session)))->toArray()
        );
    }

    /**
     * One session, in whatever state.
     *
     * 404 before 403: an unknown token is not a session this actor is being
     * refused, it is not a session at all, and answering 403 would confirm to
     * anybody with a staff token that a guessed string is somebody's live
     * checkout.
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $actor = $this->actor($request);
        $session = $this->sessions->byToken($token);

        if ($session === null) {
            abort(404, 'Not found.');
        }

        Gate::forUser($actor)->authorize('view', $session);

        return $this->payload(Wire::session(CheckoutSessionData::from($session)));
    }
}
