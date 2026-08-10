<?php

/*
 * The limiter is the deployment's, not this package's — `per_minute` is read on
 * every request rather than captured at boot, which is what lets a test set it
 * to one and what lets an operator raise it without a deploy.
 *
 * Worth an operator's attention here more than elsewhere: **retrying a
 * placement is supposed to be safe and cheap**, so a limit tight enough to
 * refuse the retry is a limit that hides the receipt. Pick one a client backing
 * off through a lost response can live inside.
 */

it('meters anonymous shoppers by address and refuses past the limit', function () {
    config()->set('checkout-api.rate_limit.per_minute', 1);

    $body = ['currency' => 'GBP', 'lines' => [['name' => 'Rain Coat', 'unit_price_minor' => 1999]]];

    $this->postJson(api('/checkout/sessions'), $body)
        ->assertCreated()
        ->assertHeader('X-RateLimit-Limit', 1)
        ->assertHeader('X-RateLimit-Remaining', 0);

    // Anonymous shopper traffic has no actor to key on, so it falls back to the
    // address. Without that, every shopper in the world would share one bucket
    // and the shop would rate-limit itself off the internet.
    $this->postJson(api('/checkout/sessions'), $body)
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('meters each actor separately, so one caller cannot spend everybody\'s budget', function () {
    config()->set('checkout-api.rate_limit.per_minute', 1);

    $one = actor(7);

    $this->actingAs($one)->getJson(api('/staff/checkout/sessions'))->assertOk();
    $this->actingAs($one)->getJson(api('/staff/checkout/sessions'))->assertStatus(429);

    // A different actor, an untouched bucket — and not the address's bucket,
    // which anybody behind the same proxy would be sharing.
    $this->actingAs(actor(7))->getJson(api('/staff/checkout/sessions'))->assertOk();
});
