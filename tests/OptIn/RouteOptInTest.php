<?php

use Illuminate\Support\Facades\Route;

/*
 * Installing this package must not publish an API, and enabling the module must
 * not publish all of it.
 *
 * The composition pinned here is the likely one — a host with its own admin
 * panel wants the shopper surface and no staff reads — and it is the one worth
 * proving, because it is the difference between "the staff reads are denied"
 * and "the staff reads do not exist". A route that is not registered has no
 * policy to get wrong, no scope to mis-derive and no controller to reach.
 */

it('registers only the groups the host opted in to', function () {
    expect(Route::has('checkout.api.v1.shopper.sessions.store'))->toBeTrue()
        ->and(Route::has('checkout.api.v1.shopper.placement.store'))->toBeTrue()
        ->and(Route::has('checkout.api.v1.staff.sessions.index'))->toBeFalse()
        ->and(Route::has('checkout.api.v1.staff.sessions.show'))->toBeFalse();
});

it('answers the group it was given', function () {
    $token = startSession();

    place(placeable())->assertOk();

    $this->getJson(api('/checkout/sessions/'.$token))->assertOk();
});

it('does not answer a group it was not given', function () {
    $token = startSession();

    // 404, not 403. There is no such route, so there is nothing to be denied by
    // — and nothing an authentication mistake could accidentally admit.
    $this->actingAs(actor(7))->getJson(api('/staff/checkout/sessions'))->assertNotFound();
    $this->actingAs(actor(7))->getJson(api('/staff/checkout/sessions/'.$token))->assertNotFound();
});
