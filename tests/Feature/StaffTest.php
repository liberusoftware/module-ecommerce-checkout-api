<?php

/*
 * The operator's surface, and the policy that is the whole of its
 * authorization.
 *
 * Nothing in this package restates a rule `CheckoutSessionPolicy` enforces, so
 * these tests are all really one assertion: the refusals come from the domain.
 * That matters more here than it looks. An unregistered policy is not a closed
 * door — Laravel's unanswered gate case is permissive, and that has produced a
 * live leak twice in this fleet — so a staff endpoint whose 403 came from a
 * hand-written `if` in a controller would still be open the day somebody
 * deleted the `if`.
 */

it('needs an actor, whatever middleware the host did or did not configure', function () {
    // `checkout-api.middleware` is empty in this suite, which is the shape a
    // host gets if it publishes the config and never fills it in. The 401 comes
    // from the base controller either way.
    $this->getJson(api('/staff/checkout/sessions'))->assertUnauthorized();
    $this->getJson(api('/staff/checkout/sessions/'.str_repeat('a', 48)))->assertUnauthorized();
});

it('lists the open sessions in the actor\'s own team, and nobody else\'s', function () {
    $mine = startSession();

    config()->set('checkout-api.tenant.team_id', 8);
    $theirs = startSession();

    $response = $this->actingAs(actor(7))->getJson(api('/staff/checkout/sessions'))->assertOk();

    $tokens = array_column((array) $response->json('data'), 'token');

    expect($tokens)->toContain($mine)
        ->and($tokens)->not->toContain($theirs);
});

it('refuses an actor who is in no team at all', function () {
    startSession();

    // `viewAny` answers false, so nothing falls back to "every team".
    $this->actingAs(actor(null))->getJson(api('/staff/checkout/sessions'))->assertForbidden();
});

it('refuses a session that belongs to another team', function () {
    $token = startSession();

    $this->actingAs(actor(9))->getJson(api('/staff/checkout/sessions/'.$token))->assertForbidden();
    $this->actingAs(actor(7))->getJson(api('/staff/checkout/sessions/'.$token))->assertOk();
});

it('answers 404 before 403 for a token that names nothing', function () {
    // An unknown token is not a session this actor is being refused, it is not
    // a session at all — and a 403 would confirm to anybody holding a staff
    // token that a guessed string is somebody's live checkout.
    $this->actingAs(actor(7))->getJson(api('/staff/checkout/sessions/'.str_repeat('z', 48)))->assertNotFound();
});

it('shows a finished session by token even though the listing is of open ones', function () {
    $token = placeable();

    place($token)->assertOk();

    // `CheckoutSessionQuery` publishes `open()`, `stale()` and `expired()` and
    // no general listing. Writing a `where` here would be this package's second
    // answer to what a staff member may see, so the listing is the open ones
    // and a specific session is reached by its token.
    $this->actingAs(actor(7))->getJson(api('/staff/checkout/sessions'))
        ->assertOk()
        ->assertJsonPath('total', 0);

    $this->actingAs(actor(7))->getJson(api('/staff/checkout/sessions/'.$token))
        ->assertOk()
        ->assertJsonPath('data.status', 'placed');
});

it('loads the lines rather than listing sessions that claim to have none', function () {
    startSession(['lines' => [
        ['name' => 'Rain Coat', 'unit_price_minor' => 1999],
        ['name' => 'Standard delivery', 'unit_price_minor' => 499, 'kind' => 'shipping'],
    ]]);

    $this->actingAs(actor(7))->getJson(api('/staff/checkout/sessions'))
        ->assertOk()
        ->assertJsonCount(2, 'data.0.lines');
});

it('narrows within a team by store and cannot widen past it', function () {
    $inStore = startSession();

    config()->set('checkout-api.tenant.store_id', 2);
    $otherStore = startSession();

    $response = $this->actingAs(actor(7))->getJson(api('/staff/checkout/sessions?store_id=1'))->assertOk();

    $tokens = array_column((array) $response->json('data'), 'token');

    expect($tokens)->toContain($inStore)->and($tokens)->not->toContain($otherStore);
});

it('bounds the page size a caller may ask for', function () {
    $this->actingAs(actor(7))->getJson(api('/staff/checkout/sessions?per_page=100000'))->assertStatus(422);
    $this->actingAs(actor(7))->getJson(api('/staff/checkout/sessions?per_page=1'))->assertOk();
});

it('publishes no staff write of any kind', function () {
    $token = startSession();

    // The policy denies `create` and `delete` outright and `update` only holds
    // while a session is open — but the stronger statement is that there is no
    // route. Every write worth having is on the shopper's own surface, with the
    // shopper's own consent behind it.
    foreach (['post', 'put', 'patch', 'delete'] as $method) {
        $this->actingAs(actor(7))
            ->json(strtoupper($method), api('/staff/checkout/sessions/'.$token))
            ->assertStatus(405);
    }
});
