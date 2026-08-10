<?php

namespace Liberu\Ecommerce\Checkout\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Liberu\Ecommerce\Checkout\Actions\AbandonCheckout;
use Liberu\Ecommerce\Checkout\Actions\ApplyDiscount;
use Liberu\Ecommerce\Checkout\Actions\PlaceCheckout;
use Liberu\Ecommerce\Checkout\Actions\RecordConsent;
use Liberu\Ecommerce\Checkout\Actions\RecordTender;
use Liberu\Ecommerce\Checkout\Actions\SetContactDetails;
use Liberu\Ecommerce\Checkout\Actions\StartCheckout;
use Liberu\Ecommerce\Checkout\Api\Http\Resources\Wire;
use Liberu\Ecommerce\Checkout\Data\CheckoutSessionData;
use Liberu\Ecommerce\Checkout\Data\LineInput;
use Liberu\Ecommerce\Checkout\Enums\LineKind;
use Liberu\Ecommerce\Checkout\Enums\TenderKind;
use Liberu\Ecommerce\Checkout\Enums\TenderStatus;
use Liberu\Ecommerce\Checkout\Queries\CheckoutSessionQuery;

/**
 * The shopper's own checkout, addressed by its token.
 *
 * Every method is the same four steps: resolve the session from the token,
 * validate, hand the work to a domain action, then re-read through
 * `CheckoutSessionQuery` so the response is the module's own view of the row
 * rather than this package's guess at it.
 *
 * Nothing here writes a model, computes a total or decides whether a checkout
 * may be placed. The actions enforce the invariants and dispatch the events,
 * and a caller that wrote a row directly would bypass both.
 *
 * **Three facts never come from the request body**, and that is the security
 * boundary of this file. `team_id`, `store_id` and `customer_id` are read from
 * request attributes the host's own middleware sets, falling back to
 * configuration. This group is the one an anonymous caller can reach, and an
 * anonymous caller asserting `"customer_id": 42` is an anonymous caller filing
 * an order against somebody else's account. See `config/checkout-api.php`.
 *
 * **There is no cart here**, and no way to ask for one. Lines arrive in the
 * body. That is the domain's rule and not a simplification: a price a shopper
 * agreed to must not be able to move between the agreeing and the charging, so
 * the session copies its lines, and once the copy is forced there is nothing
 * left for a cart lookup to buy.
 */
class ShopperController extends Controller
{
    public function __construct(private readonly CheckoutSessionQuery $sessions) {}

    /**
     * Start a session from lines handed in.
     *
     * The lines are the whole payload and the mapping is the caller's: what a
     * line *is*, which tax rate applies, what shipping was quoted and what a
     * coupon came to are all decisions somebody else already made. This module
     * freezes them.
     *
     * Tax arrives per line as a rate in basis points or as an amount, never
     * looked up. Handing in an amount is a one-way door — `ApplyDiscount` then
     * refuses, because reallocating an amount would mean deriving a rate from
     * it. If a coupon can be entered after tax is computed, supply rates.
     */
    public function start(Request $request, StartCheckout $action): JsonResponse
    {
        $max = (int) config('checkout-api.max_lines', 200);

        $input = $request->validate([
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'exponent' => ['integer', 'min:0', 'max:4'],
            'email' => ['nullable', 'email', 'max:255'],
            'discount_minor' => ['integer', 'min:0'],
            'expires_in_minutes' => ['nullable', 'integer', 'min:1'],
            'shipping_address' => ['nullable', 'array'],
            'billing_address' => ['nullable', 'array'],
            'lines' => ['required', 'array', 'min:1', 'max:'.$max],
            'lines.*.name' => ['required', 'string', 'max:255'],
            'lines.*.unit_price_minor' => ['required', 'integer', 'min:0'],
            'lines.*.quantity' => ['integer', 'min:1'],
            'lines.*.kind' => ['string', Rule::enum(LineKind::class)],
            'lines.*.sku' => ['nullable', 'string', 'max:255'],
            'lines.*.product_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.taxable' => ['boolean'],
            'lines.*.tax_rate_bp' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'lines.*.tax_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.metadata' => ['nullable', 'array'],
            'lines.*.position' => ['integer', 'min:0'],
        ]);

        $session = $action->handle(
            currency: (string) $input['currency'],
            lines: array_map(LineInput::fromArray(...), array_values((array) $input['lines'])),
            teamId: $this->tenant($request, 'team_id'),
            storeId: $this->tenant($request, 'store_id'),
            customerId: $this->tenant($request, 'customer_id'),
            email: $input['email'] ?? null,
            currencyExponent: (int) ($input['exponent'] ?? 2),
            discountMinor: (int) ($input['discount_minor'] ?? 0),
            shippingAddress: $input['shipping_address'] ?? null,
            billingAddress: $input['billing_address'] ?? null,
            expiresInMinutes: $input['expires_in_minutes'] ?? null,
        );

        return $this->session((string) $session->token, 201);
    }

    public function show(Request $request, string $token): JsonResponse
    {
        return $this->session($token);
    }

    /**
     * Who the order is for and where it goes.
     *
     * Null leaves a field alone, which is what a multi-step checkout needs: the
     * shipping step must not blank the email the contact step collected. An
     * address is stored whole and validated as an object and nothing more —
     * address format is a per-country problem with a long tail, and a package
     * shipping its own opinion about it releases every time a country
     * disagrees.
     */
    public function contact(Request $request, string $token, SetContactDetails $action): JsonResponse
    {
        $session = $this->find($token);

        $input = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'shipping_address' => ['nullable', 'array'],
            'billing_address' => ['nullable', 'array'],
        ]);

        $action->handle(
            session: $session,
            email: $input['email'] ?? null,
            shippingAddress: $input['shipping_address'] ?? null,
            billingAddress: $input['billing_address'] ?? null,
            customerId: $this->tenant($request, 'customer_id'),
        );

        return $this->session($token);
    }

    /**
     * The basket-wide discount, already decided elsewhere.
     *
     * No coupon is validated here and no code is looked up. What the module
     * owns is where the money lands: pro-rata across every line with untaxable
     * lines in the denominator, and tax reapplied to the new nets.
     *
     * A session whose tax arrived as an amount answers 422 —
     * `TaxAmountNotReallocatable` — because scaling that amount would mean
     * inventing a rate from it. That is a legitimate refusal of a well-formed
     * request, not a fault, and the message says what to do about it.
     */
    public function discount(Request $request, string $token, ApplyDiscount $action): JsonResponse
    {
        $session = $this->find($token);

        $input = $request->validate(['discount_minor' => ['required', 'integer', 'min:0']]);

        $action->handle($session, (int) $input['discount_minor']);

        return $this->session($token);
    }

    /**
     * Evidence: what was agreed, which version of the text, when, and from
     * where.
     *
     * The IP and the user agent are read from the request here because this is
     * the only layer that has one — the domain action takes them as arguments
     * precisely so that it stays drivable from a console command. They are
     * written to the consent row and go no further: they are not in the
     * response, not in the log line, and not in any other surface this package
     * publishes.
     *
     * `agreed: false` is a first-class value. A shopper who declined marketing
     * has said something, and a missing row cannot tell that apart from a form
     * that never rendered.
     */
    public function consent(Request $request, string $token, RecordConsent $action): JsonResponse
    {
        $session = $this->find($token);

        $input = $request->validate([
            'type' => ['required', 'string', 'max:191'],
            'document_version' => ['required', 'string', 'max:191'],
            'document_url' => ['nullable', 'string', 'max:2048'],
            'agreed' => ['boolean'],
        ]);

        $action->handle(
            session: $session,
            type: (string) $input['type'],
            documentVersion: (string) $input['document_version'],
            agreed: (bool) ($input['agreed'] ?? true),
            documentUrl: $input['document_url'] ?? null,
            ipAddress: $request->ip(),
            userAgent: mb_substr((string) $request->userAgent(), 0, 1024),
        );

        return $this->session($token, 201);
    }

    /**
     * What a provider reported, without this package ever having asked one.
     *
     * `provider` is an opaque string the host chooses and nothing here
     * interprets. There is no gateway, no SDK and no brand name anywhere in
     * `src/` — a checkout's entire relationship with payment is arithmetic:
     * does what came back equal what is owed. A gift card and store credit
     * arrive the same way, as a kind, an amount and a reference.
     *
     * Several tenders per session are allowed, and only `authorized` and
     * `captured` ones count towards the total. The whole session comes back so
     * a client can see what is still outstanding without a second request.
     *
     * **This endpoint is not idempotent and does not pretend to be.** A
     * duplicate tender is caught where it matters, at placement, by the exact
     * sum rule: over-tendering refuses as loudly as under-tendering, because a
     * refund is not something this module can issue.
     */
    public function tender(Request $request, string $token, RecordTender $action): JsonResponse
    {
        $session = $this->find($token);

        $input = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:0'],
            'kind' => ['string', Rule::enum(TenderKind::class)],
            'status' => ['string', Rule::enum(TenderStatus::class)],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'provider' => ['nullable', 'string', 'max:191'],
            'reference' => ['nullable', 'string', 'max:191'],
            'metadata' => ['nullable', 'array'],
        ]);

        $action->handle(
            session: $session,
            amountMinor: (int) $input['amount_minor'],
            kind: TenderKind::from((string) ($input['kind'] ?? TenderKind::Payment->value)),
            status: TenderStatus::from((string) ($input['status'] ?? TenderStatus::Pending->value)),
            currency: $input['currency'] ?? null,
            provider: $input['provider'] ?? null,
            reference: $input['reference'] ?? null,
            metadata: $input['metadata'] ?? null,
        );

        return $this->session($token, 201);
    }

    /**
     * Commit the checkout, exactly once, and say which time this was.
     *
     * **`Idempotency-Key` is required.** Not optional, and not generated here:
     * a key minted server-side is a new key on every retry, which is the same
     * as no key at all, and the whole failure this endpoint exists to prevent
     * is the retry of a request whose response never arrived. So a missing
     * header is 400 — a fault in the request itself that no change to the body
     * can fix — rather than a silent, unprotected commit.
     *
     * The key belongs to *this attempt to place this checkout*. A client mints
     * one when it renders the Place Order button and keeps it across every
     * retry; a key regenerated per click protects nothing.
     *
     * Four outcomes, four answers:
     *
     * - **200, `Idempotency-Replayed: false`** — the commit happened here, and
     *   `CheckoutCompleted` was dispatched.
     * - **200, `Idempotency-Replayed: true`** — a retry. The stored result is
     *   replayed byte for byte, no work runs and **no event is dispatched**.
     *   The same status as a first commit on purpose: a client must not have to
     *   branch on a status code to find out whether its order exists.
     * - **409** — the key was spent on a different payload. Two genuinely
     *   different requests sharing a key, and the only safe answers are
     *   "conflict" or "commit twice". Replaying the first result would be the
     *   third and worst: a success returned for something that never happened.
     * - **423** — the key is claimed by a call that has not finished. Retry in
     *   a moment, unchanged.
     *
     * A throw releases the claim, so a placement that fails its guards does not
     * burn the key it was tried with. The shopper fixes their address, or the
     * missing tender arrives, and the retry uses the same key — which is what a
     * client naturally does.
     */
    public function place(Request $request, string $token, PlaceCheckout $action): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '') {
            abort(400, 'This operation requires an Idempotency-Key header naming this attempt to place this checkout.');
        }

        // The domain stores the key in a `varchar(191)` under a unique index.
        // Refusing a longer one here is the difference between a clear 400 and
        // a driver-level truncation that would make two different keys equal.
        if (mb_strlen($key) > 191) {
            abort(400, 'An Idempotency-Key may be at most 191 characters.');
        }

        $placement = $action->handle($this->find($token), $key);

        return response()
            ->json([
                'data' => Wire::placed($placement->checkout),
                // The same fact as the header, in the body, because a client
                // reading a stored response has the body and not the headers.
                'replayed' => $placement->replayed,
            ])
            ->header('Idempotency-Replayed', $placement->replayed ? 'true' : 'false');
    }

    /**
     * Close a session that will not be placed.
     *
     * Only ever `abandoned`, never `expired`. The two are kept apart by the
     * domain because they answer different questions in a funnel report and
     * only one is worth a recovery email — and expiry is the merchant's price
     * snapshot running out, which is not a thing a caller may assert. The
     * host's own schedule closes expired sessions.
     */
    public function abandon(Request $request, string $token, AbandonCheckout $action): JsonResponse
    {
        $session = $this->find($token);

        $input = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $action->handle($session, expired: false, reason: $input['reason'] ?? null);

        return $this->session($token);
    }

    /**
     * The session behind a token, or 404.
     *
     * A token that names nothing and a token that names somebody else's
     * checkout answer identically, because the token is the credential.
     */
    private function find(string $token): object
    {
        $session = $this->sessions->byToken($token);

        if ($session === null) {
            abort(404, 'Not found.');
        }

        return $session;
    }

    /** Re-read through the domain's own query, so the response is its view. */
    private function session(string $token, int $status = 200): JsonResponse
    {
        return $this->payload(Wire::session(CheckoutSessionData::from($this->find($token))), $status);
    }

    /**
     * A tenancy or identity value the *host* resolved, never the caller.
     *
     * An attribute set by the host's middleware wins; a configured default
     * stands in for a single-tenant deployment. Null on all three is legal and
     * means a session belonging to nobody, which is exactly what a small shop
     * with no teams has.
     */
    private function tenant(Request $request, string $key): ?int
    {
        $value = $request->attributes->get('checkout.'.$key)
            ?? config('checkout-api.tenant.'.$key);

        return $value === null ? null : (int) $value;
    }
}
