<?php

use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Checkout\Events\CheckoutAbandoned;
use Liberu\Ecommerce\Checkout\Events\CheckoutStarted;

/*
 * The session, from the shopper's side.
 *
 * The one thing every test here relies on and none of them installs is a cart.
 * There is no `carts` table in this suite, no cart module in the manifest and
 * no route that takes a cart id — a session is started from lines in a request
 * body and from nothing else.
 */

it('starts a session from lines handed in, with no cart anywhere', function () {
    Event::fake([CheckoutStarted::class]);

    $response = $this->postJson(api('/checkout/sessions'), [
        'currency' => 'gbp',
        'email' => 'shopper@example.test',
        'lines' => [
            ['name' => 'Rain Coat', 'unit_price_minor' => 1999, 'quantity' => 2, 'tax_rate_bp' => 2000],
            ['name' => 'Standard delivery', 'unit_price_minor' => 499, 'kind' => 'shipping', 'tax_rate_bp' => 2000],
        ],
    ])->assertCreated();

    expect($response->json('data.token'))->toBeString()->toHaveLength(48)
        ->and($response->json('data.status'))->toBe('open')
        ->and($response->json('data.currency'))->toBe('GBP')
        ->and($response->json('data.lines'))->toHaveCount(2)
        ->and($response->json('data.lines.1.kind'))->toBe('shipping')
        // Tax is per line, half up, and only then summed — 3998 at 20% is 800
        // and 499 at 20% is 100 (99.8 rounded up), so 900 rather than the 899
        // a single multiplication of the blended subtotal would give.
        ->and($response->json('data.totals.subtotal.minor'))->toBe(4497)
        ->and($response->json('data.totals.tax.minor'))->toBe(900)
        ->and($response->json('data.totals.grand_total.minor'))->toBe(5397);

    Event::assertDispatched(CheckoutStarted::class);
});

it('files the session into the tenant the host resolved, never the one the caller asked for', function () {
    // A caller trying to name their own tenant and their own identity. Both are
    // ignored: the shopper group is the one an anonymous caller can reach, and
    // an anonymous caller asserting `customer_id` is filing an order against
    // somebody else's account.
    $token = startSession(['team_id' => 999, 'store_id' => 999, 'customer_id' => 999]);

    expect(readSession($token)['customer_id'])->toBeNull();

    // The team it really got is the configured one, proved through the staff
    // group — which only ever shows an actor their own team's sessions.
    $this->actingAs(actor(7))->getJson(api('/staff/checkout/sessions'))
        ->assertOk()
        ->assertJsonPath('data.0.token', $token);
});

it('answers 404 for a token that names nothing, exactly as it does for one that names somebody else', function () {
    // Same answer both ways on purpose. The token *is* the credential, so a 403
    // on an unknown one would confirm that a guessed string is a real checkout.
    $this->getJson(api('/checkout/sessions/'.str_repeat('a', 48)))->assertNotFound();

    // Short of the route constraint, so the router refuses it before anything
    // touches the database.
    $this->getJson(api('/checkout/sessions/nope'))->assertNotFound();
});

it('sets contact details without blanking what an earlier step collected', function () {
    $token = startSession(['email' => 'first@example.test']);

    $this->putJson(api('/checkout/sessions/'.$token.'/contact'), [
        'shipping_address' => ['line1' => '1 High Street', 'postcode' => 'SW1A 1AA', 'country' => 'GB'],
    ])->assertOk();

    $session = readSession($token);

    expect($session['email'])->toBe('first@example.test')
        ->and($session['shipping_address']['postcode'])->toBe('SW1A 1AA');

    $this->putJson(api('/checkout/sessions/'.$token.'/contact'), ['email' => 'second@example.test'])->assertOk();

    $session = readSession($token);

    expect($session['email'])->toBe('second@example.test')
        ->and($session['shipping_address']['postcode'])->toBe('SW1A 1AA');
});

it('applies a discount pro-rata across every line', function () {
    $token = startSession(['lines' => [
        ['name' => 'Coat', 'unit_price_minor' => 6000, 'tax_rate_bp' => 2000],
        ['name' => 'Book', 'unit_price_minor' => 4000, 'taxable' => false],
    ]]);

    $this->putJson(api('/checkout/sessions/'.$token.'/discount'), ['discount_minor' => 1000])->assertOk();

    $session = readSession($token);

    // The untaxable line is in the denominator, which is the rule the fleet
    // settled at the tax merge. Leaving it out would concentrate the discount
    // on the coat and under-tax it.
    expect($session['lines'][0]['discount']['minor'])->toBe(600)
        ->and($session['lines'][1]['discount']['minor'])->toBe(400)
        ->and($session['totals']['discount']['minor'])->toBe(1000)
        ->and($session['totals']['net']['minor'])->toBe(9000)
        ->and($session['totals']['tax']['minor'])->toBe(1080);
});

it('refuses to move a discount under a line whose tax came in as an amount', function () {
    $token = startSession(['lines' => [
        ['name' => 'Coat', 'unit_price_minor' => 6000, 'tax_minor' => 1200],
    ]]);

    // 422 and not 500. The request is well formed and the rules say no: this
    // module will not derive a rate from an amount, so whoever computed the tax
    // is the one who can compute it again.
    $this->putJson(api('/checkout/sessions/'.$token.'/discount'), ['discount_minor' => 1000])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'arrived as an amount'));
});

it('refuses a discount larger than the subtotal', function () {
    $token = startSession();

    $this->putJson(api('/checkout/sessions/'.$token.'/discount'), ['discount_minor' => 999999])->assertStatus(422);
});

it('abandons a session, and only ever as abandoned', function () {
    Event::fake([CheckoutAbandoned::class]);

    $token = startSession();

    $this->deleteJson(api('/checkout/sessions/'.$token), ['reason' => 'changed their mind'])
        ->assertOk()
        ->assertJsonPath('data.status', 'abandoned');

    // Expiry is the merchant's price snapshot running out, not something a
    // caller may assert. There is no parameter for it and no route to it.
    Event::assertDispatched(CheckoutAbandoned::class);
});

it('answers 409 once a session has stopped being open', function () {
    $token = startSession();

    $this->deleteJson(api('/checkout/sessions/'.$token))->assertOk();

    // Conflict rather than 422: the request is fine, the resource has moved on,
    // and no edit to the body will change that.
    $this->putJson(api('/checkout/sessions/'.$token.'/discount'), ['discount_minor' => 1])->assertStatus(409);
    $this->putJson(api('/checkout/sessions/'.$token.'/contact'), ['email' => 'x@example.test'])->assertStatus(409);
    $this->postJson(api('/checkout/sessions/'.$token.'/tenders'), ['amount_minor' => 1])->assertStatus(409);
    $this->deleteJson(api('/checkout/sessions/'.$token))->assertStatus(409);

    // And a placed or abandoned session stays *readable*, which is how a client
    // that lost its response finds out what happened.
    $this->getJson(api('/checkout/sessions/'.$token))->assertOk();
});

it('validates the payload before the domain ever sees it', function () {
    $this->postJson(api('/checkout/sessions'), [])->assertStatus(422);
    $this->postJson(api('/checkout/sessions'), ['currency' => 'GBP', 'lines' => []])->assertStatus(422);
    $this->postJson(api('/checkout/sessions'), [
        'currency' => 'POUNDS',
        'lines' => [['name' => 'x', 'unit_price_minor' => 1]],
    ])->assertStatus(422);
    $this->postJson(api('/checkout/sessions'), [
        'currency' => 'GBP',
        'lines' => [['name' => 'x', 'unit_price_minor' => -1]],
    ])->assertStatus(422);
    $this->postJson(api('/checkout/sessions'), [
        'currency' => 'GBP',
        'lines' => [['name' => 'x', 'unit_price_minor' => 1, 'kind' => 'discount']],
    ])->assertStatus(422);
});

it('will not accept more lines than one transaction should carry', function () {
    config()->set('checkout-api.max_lines', 2);

    $this->postJson(api('/checkout/sessions'), [
        'currency' => 'GBP',
        'lines' => array_fill(0, 3, ['name' => 'x', 'unit_price_minor' => 100]),
    ])->assertStatus(422);
});
