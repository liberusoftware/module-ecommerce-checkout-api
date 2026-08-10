<?php

use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Checkout\Events\CheckoutCompleted;

/*
 * The guards, as status codes.
 *
 * Every one of these is a refusal of a well-formed request, so every one of
 * them is a 4xx and none of them is a 500. A checkout that cannot be placed is
 * not an incident.
 *
 * The guards run *inside* the idempotency claim, which is what makes the retry
 * cases in `IdempotencyTest` work: a repeat arrives after the first call has
 * closed the session, and a guard that ran first would answer "not open" to the
 * one caller who most needs to hear "yes, it worked".
 */

it('refuses a checkout nobody can be told about', function () {
    $token = startSession(['email' => null]);

    tenderInFull($token);

    place($token)
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'email address'));
});

it('refuses when the money that came back is short of the money that is owed', function () {
    $token = startSession();

    tenderInFull($token, grandTotal($token) - 1);

    place($token)->assertStatus(422);
});

it('refuses an over-tender as loudly as an under-tender', function () {
    $token = startSession();

    tenderInFull($token, grandTotal($token) + 1);

    // Not a rounding nicety: a refund is not something this module can issue,
    // so taking too much and calling it placed would leave money owed that
    // nothing here can give back.
    place($token)->assertStatus(422);
});

it('does not count a tender the provider has not confirmed', function () {
    $token = startSession();

    $this->postJson(api('/checkout/sessions/'.$token.'/tenders'), [
        'amount_minor' => grandTotal($token),
        'status' => 'pending',
    ])->assertCreated();

    // Only `authorized` and `captured` count towards the total, so a session
    // whose only tender is pending owes everything it owed before.
    place($token)->assertStatus(422);
});

it('refuses a snapshot whose clock has run out, whether or not a sweep has run', function () {
    $token = placeable(['expires_in_minutes' => 10]);

    $this->travel(11)->minutes();

    // 409: the resource has moved on and no edit to the request changes that.
    // Nothing swept this session — `PlaceCheckout` asks the clock rather than
    // the column, so the window holds even when the host's schedule does not.
    place($token)->assertStatus(409);
});

it('refuses to place a session that was abandoned', function () {
    $token = placeable();

    $this->deleteJson(api('/checkout/sessions/'.$token))->assertOk();

    place($token)->assertStatus(409);
});

it('dispatches exactly one event carrying everything an order needs', function () {
    Event::fake([CheckoutCompleted::class]);

    $token = placeable([
        'email' => 'shopper@example.test',
        'lines' => [
            ['name' => 'Rain Coat', 'unit_price_minor' => 6000, 'quantity' => 1, 'tax_rate_bp' => 2000, 'sku' => 'RC-1', 'product_id' => 12],
            ['name' => 'Standard delivery', 'unit_price_minor' => 499, 'kind' => 'shipping', 'tax_rate_bp' => 2000],
        ],
    ]);

    place($token)->assertOk();

    Event::assertDispatched(CheckoutCompleted::class, function (CheckoutCompleted $event) use ($token): bool {
        return $event->checkout->token === $token
            && $event->checkout->email === 'shopper@example.test'
            && $event->checkout->idempotencyKey === 'idem_key_one'
            && count($event->checkout->lines) === 2;
    });
});

it('leaves the placed checkout readable, which is how a lost response is recovered', function () {
    $token = placeable();

    place($token)->assertOk();

    $this->getJson(api('/checkout/sessions/'.$token))
        ->assertOk()
        ->assertJsonPath('data.status', 'placed')
        ->assertJsonPath('data.placed_at', fn (?string $at): bool => $at !== null);
});
