<?php

/*
 * What a provider reported, without this package ever having asked one.
 *
 * There is no gateway here, no SDK, no provider interface and no brand name
 * anywhere in `src/` — `BoundaryTest` greps for the latter. A tender is a kind,
 * a status, an amount, a currency, an opaque provider string and a reference,
 * and a checkout's entire relationship with payment is arithmetic: does what
 * came back equal what is owed.
 */

it('records what the host says a provider said, and interprets none of it', function () {
    $token = startSession();

    $response = $this->postJson(api('/checkout/sessions/'.$token.'/tenders'), [
        'amount_minor' => 2399,
        'kind' => 'payment',
        'status' => 'authorized',
        'provider' => 'whatever-the-host-called-it',
        'reference' => 'ref_abc123',
        'metadata' => ['last4' => '4242'],
    ])->assertCreated();

    expect($response->json('data.tenders.0'))
        ->toMatchArray([
            'kind' => 'payment',
            'status' => 'authorized',
            'provider' => 'whatever-the-host-called-it',
            'reference' => 'ref_abc123',
        ])
        ->and($response->json('data.tenders.0.amount'))
        ->toBe(['minor' => 2399, 'currency' => 'GBP', 'exponent' => 2, 'decimal' => '23.99']);
});

it('takes a gift card and store credit the same way it takes a card', function () {
    $token = startSession();

    foreach (['gift_card', 'store_credit'] as $kind) {
        $this->postJson(api('/checkout/sessions/'.$token.'/tenders'), [
            'amount_minor' => 100,
            'kind' => $kind,
            'status' => 'captured',
            'reference' => 'instrument-'.$kind,
        ])->assertCreated();
    }

    // Three facts, the same three a card authorisation arrives with. This
    // module maintains no balance and cannot tell you what a card is worth: the
    // ledger belongs to whoever issues the instrument, and a second reader of
    // somebody else's balance is a second answer waiting to disagree.
    expect(readSession($token)['tenders'])->toHaveCount(2);
});

it('refuses a tender in a currency the session is not in', function () {
    $token = startSession();

    $this->postJson(api('/checkout/sessions/'.$token.'/tenders'), [
        'amount_minor' => 100,
        'currency' => 'USD',
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'exchange rate'));
});

it('refuses a negative tender, because a refund is not one', function () {
    $token = startSession();

    // Caught by validation before the domain sees it, and the domain refuses it
    // again behind that. Both are 422, so a client sees one answer either way.
    $this->postJson(api('/checkout/sessions/'.$token.'/tenders'), ['amount_minor' => -100])->assertStatus(422);
});

it('rejects a kind or a status the domain does not publish', function () {
    $token = startSession();

    $this->postJson(api('/checkout/sessions/'.$token.'/tenders'), [
        'amount_minor' => 100,
        'kind' => 'crypto',
    ])->assertStatus(422);

    $this->postJson(api('/checkout/sessions/'.$token.'/tenders'), [
        'amount_minor' => 100,
        'status' => 'settled',
    ])->assertStatus(422);
});

it('allows several tenders against one session', function () {
    $token = startSession();
    $total = grandTotal($token);

    $this->postJson(api('/checkout/sessions/'.$token.'/tenders'), [
        'amount_minor' => 400,
        'kind' => 'gift_card',
        'status' => 'captured',
    ])->assertCreated();

    $this->postJson(api('/checkout/sessions/'.$token.'/tenders'), [
        'amount_minor' => $total - 400,
        'status' => 'captured',
    ])->assertCreated();

    // Splitting a total across a gift card and a card is not a feature this
    // package implements; nothing in the model forbids it, so it places.
    place($token)->assertOk();
});
