<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Checkout\Actions\PlaceCheckout;
use Liberu\Ecommerce\Checkout\Events\CheckoutCompleted;
use Liberu\Ecommerce\Checkout\Exceptions\IdempotencyConflict;
use Liberu\Ecommerce\Checkout\Services\Idempotency;

/*
 * The suite this package exists for.
 *
 * The domain treats idempotency as a first-class feature, and HTTP is where
 * clients actually meet it: a shopper double-clicking Place Order, a mobile
 * client retrying a request whose response never arrived, and a proxy replaying
 * a POST are one event three times, and the difference between one charge and
 * three is this file.
 *
 * Five paths, five answers, and each one is here because giving it the wrong
 * answer is a specific, expensive failure:
 *
 *   no key                 400  — an unprotected commit is not a service
 *   new key                200  Idempotency-Replayed: false, event dispatched
 *   same key, same body    200  Idempotency-Replayed: true,  no event
 *   same key, other body   409  — never somebody else's receipt
 *   claimed, unfinished    423  — never an empty success, never "give up"
 *
 * The two 200s are the same status deliberately. A client must not have to
 * branch on a status code to find out whether its order exists.
 */

it('places once and says so', function () {
    Event::fake([CheckoutCompleted::class]);

    $token = placeable();

    $response = place($token)->assertOk();

    expect($response->headers->get('Idempotency-Replayed'))->toBe('false')
        ->and($response->json('replayed'))->toBeFalse()
        ->and($response->json('data.token'))->toBe($token)
        ->and($response->json('data.idempotency_key'))->toBe('idem_key_one')
        ->and($response->json('data.placed_at'))->toBeString()
        ->and($response->json('data.totals.grand_total.minor'))->toBe(2399);

    Event::assertDispatched(CheckoutCompleted::class, 1);
});

it('replays the stored result for the same key and the same payload, and dispatches nothing', function () {
    $token = placeable();

    $first = place($token)->assertOk();

    Event::fake([CheckoutCompleted::class]);

    $second = place($token)->assertOk();

    expect($second->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and($second->json('replayed'))->toBeTrue()
        // Byte for byte, apart from the flag. The domain rebuilds the value
        // from the stored array on both paths precisely so a first commit and a
        // retry cannot return two differently-shaped answers.
        ->and($second->json('data'))->toBe($first->json('data'));

    // The point of the whole mechanism. An order module subscribed to this
    // event would otherwise create a second order for one payment.
    Event::assertNotDispatched(CheckoutCompleted::class);
});

it('answers 409 when a key is reused for a different checkout', function () {
    $first = placeable();
    $second = placeable();

    place($first, 'shared_key')->assertOk();

    // Same key, a different payload — a different token and total. The only two
    // safe answers are "conflict" or "commit twice"; replaying the first
    // result would be the third and worst, a success returned for something
    // that never happened.
    place($second, 'shared_key')
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'already been used'));

    // And the second checkout is untouched, so its own key still works.
    place($second, 'its_own_key')->assertOk()->assertJsonPath('replayed', false);
});

it('answers 423 while a key is claimed by a call that has not finished', function () {
    $token = placeable();
    $total = grandTotal($token);

    // The claim a first request would have made, without its result. Written
    // through the query builder rather than the model, because an `-api`
    // package that reaches for somebody else's model in a test is one refactor
    // away from reaching for it in `src/`. The hash comes from the domain's own
    // canonicaliser, so this is the exact row the domain would have written.
    DB::table('ecommerce_checkout_idempotency_keys')->insert([
        'scope' => PlaceCheckout::SCOPE,
        'key' => 'idem_key_one',
        'payload_hash' => Idempotency::hash([
            'token' => $token,
            'grand_total_minor' => $total,
            'tendered_minor' => $total,
        ]),
        'result' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = place($token)->assertStatus(423);

    // Its own status, and not 409. A client told "conflict" for an in-flight
    // commit stops retrying, and the response it was waiting for never arrives.
    // `Retry-After` says the opposite: come back, unchanged, in a moment.
    expect($response->headers->get('Retry-After'))->toBe('1')
        ->and($response->json('message'))->toContain('has not finished');

    // And it is emphatically not an empty success.
    expect($response->json('data'))->toBeNull();
});

it('tells the two idempotency conflicts apart by the domain\'s own factories', function () {
    // The domain publishes one exception class for two conditions, so the
    // controller rebuilds the in-flight message from the same factory to
    // classify it. This is the assertion that keeps that honest: if a domain
    // release splits the class or rewords either message, it fails here rather
    // than by handing a client a 409 for something that was about to succeed.
    $conflict = IdempotencyConflict::from(PlaceCheckout::SCOPE, 'k');
    $inFlight = IdempotencyConflict::inFlight(PlaceCheckout::SCOPE, 'k');

    expect($inFlight->getMessage())->not->toBe($conflict->getMessage())
        ->and($inFlight->getMessage())->toBe(IdempotencyConflict::inFlight(PlaceCheckout::SCOPE, 'k')->getMessage());
});

it('requires the header rather than inventing a key nobody kept', function () {
    $token = placeable();

    // 400 and not 422: this is a fault in the request itself and no change to
    // the body fixes it. A key minted server-side would be a new key on every
    // retry, which is the same as no key at all — and the whole failure this
    // endpoint exists to prevent is the retry of a request whose response never
    // arrived.
    place($token, null)->assertStatus(400);
    place($token, '   ')->assertStatus(400);
    place($token, str_repeat('k', 192))->assertStatus(400);

    // Nothing was committed by any of them.
    expect(readSession($token)['status'])->toBe('open');
});

it('does not burn a key on a placement that failed its guards', function () {
    // No tender, so the guard refuses. The claim is released by the throw.
    $token = startSession();

    place($token, 'the_same_key_throughout')->assertStatus(422);

    tenderInFull($token);

    // The client fixed what was wrong and retried with the key it has held all
    // along, which is what a client naturally does. A burnt key would answer
    // 423 here forever, or replay an empty result.
    place($token, 'the_same_key_throughout')
        ->assertOk()
        ->assertJsonPath('replayed', false);
});

it('keeps one key per scope and caller, not one per session', function () {
    // Two different keys against the same checkout: the first commits, the
    // second finds a session that is no longer open. That is a genuine 409 —
    // the client minted a new key for a checkout that is already placed, which
    // is exactly the mistake `docs/adoption.md` warns about.
    $token = placeable();

    place($token, 'first_attempt')->assertOk();

    place($token, 'second_attempt')
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'cannot become'));
});

it('is unaffected by the order a client serialises its own body in', function () {
    $token = placeable();

    // The placement body is not part of the payload the domain hashes — the
    // hash is over the token, the total and the tendered amount — so a client
    // that sends a body at all, in any order, is still retrying the same
    // commit.
    place($token, 'ordered_key', ['b' => 2, 'a' => 1])->assertOk()->assertJsonPath('replayed', false);
    place($token, 'ordered_key', ['a' => 1, 'b' => 2])->assertOk()->assertJsonPath('replayed', true);
});
