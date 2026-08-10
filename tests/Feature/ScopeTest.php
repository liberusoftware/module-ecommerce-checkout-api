<?php

use Liberu\Ecommerce\Checkout\Api\Http\Scopes;

/*
 * A token may only do what it was issued for.
 *
 * The audience is what is scoped, not the resource. A token that may drive one
 * shopper's checkout and a token that may list every open session in a team are
 * categorically different grants, and a single `checkout:sessions.read`
 * covering both would make that difference invisible to whoever issues the
 * token.
 *
 * This is always a narrowing. The policy still runs and still decides; a token
 * carrying every scope gets exactly the access its owner has.
 */

it('derives the scope from the group and the method, not from a per-route argument', function () {
    expect(Scopes::forRoute('checkout.api.v1.shopper.sessions.show', 'GET'))->toBe('checkout:shopper.read')
        ->and(Scopes::forRoute('checkout.api.v1.shopper.placement.store', 'POST'))->toBe('checkout:shopper.write')
        ->and(Scopes::forRoute('checkout.api.v1.staff.sessions.index', 'GET'))->toBe('checkout:staff.read')
        // HEAD rides along with GET and is as safe.
        ->and(Scopes::forRoute('checkout.api.v1.staff.sessions.show', 'HEAD'))->toBe('checkout:staff.read')
        // Somebody else's route.
        ->and(Scopes::forRoute('catalog.api.v1.storefront.products.index', 'GET'))->toBeNull();
});

it('is not confused by a version segment a host chose badly', function () {
    // `{version}` is configuration and a host may set it to anything, including
    // something with a dot in it. Matching on the group name rather than on
    // position is what makes that harmless.
    expect(Scopes::forRoute('checkout.api.2.1.staff.sessions.index', 'GET'))->toBe('checkout:staff.read');
});

it('publishes no scope with nothing behind it', function () {
    // `checkout:staff.write` is absent because the staff group has no write
    // route. A scope with nothing behind it is a promise to a token issuer that
    // nothing checks.
    expect(Scopes::ALL)->toBe([
        'checkout:shopper.read',
        'checkout:shopper.write',
        'checkout:staff.read',
    ]);
});

it('refuses an actor whose token is missing the scope', function () {
    $token = startSession();

    $this->actingAs(tokenActor(['checkout:shopper.read']))
        ->getJson(api('/checkout/sessions/'.$token))
        ->assertOk();

    // Read, but not write.
    $this->actingAs(tokenActor(['checkout:shopper.read']))
        ->putJson(api('/checkout/sessions/'.$token.'/discount'), ['discount_minor' => 100])
        ->assertForbidden();

    $this->actingAs(tokenActor(['checkout:shopper.write']))
        ->putJson(api('/checkout/sessions/'.$token.'/discount'), ['discount_minor' => 100])
        ->assertOk();
});

it('keeps the two audiences apart even for one actor', function () {
    $token = startSession();

    // A shopper-scoped token is not a staff token, whatever its owner's policy
    // says.
    $this->actingAs(tokenActor(['checkout:shopper.read', 'checkout:shopper.write']))
        ->getJson(api('/staff/checkout/sessions'))
        ->assertForbidden();

    $this->actingAs(tokenActor(['checkout:staff.read']))
        ->getJson(api('/staff/checkout/sessions/'.$token))
        ->assertOk();
});

it('leaves a session-authenticated caller to the policy alone', function () {
    // An actor with no `tokenCan()` carries no scope list to check. Inventing
    // one would mean denying every first-party session caller or admitting
    // every one, and both are lies about what was checked. The OpenAPI document
    // says so in as many words.
    $this->actingAs(actor(7))->getJson(api('/staff/checkout/sessions'))->assertOk();
});

it('needs no scope at all when nobody is asking', function () {
    // The shopper group usually has no actor, and then there is nothing to
    // narrow. That is not a hole: what the request may reach is decided by
    // possession of a 48-character token naming exactly one checkout, and no
    // scope list would make that narrower.
    $token = startSession();

    $this->getJson(api('/checkout/sessions/'.$token))->assertOk();
});
