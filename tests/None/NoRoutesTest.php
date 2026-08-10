<?php

use Illuminate\Support\Facades\Route;

/*
 * Installing an API package must not publish an API.
 *
 * The deployment decides which operations it is prepared to answer for, and a
 * package that starts accepting a payment instruction because Composer ran has
 * made that commitment on the operator's behalf. So the shipped default is the
 * one pinned here: enabled, booted, and answering nothing.
 */

it('publishes no route until the host names a group', function () {
    expect(Route::has('checkout.api.v1.shopper.sessions.store'))->toBeFalse()
        ->and(Route::has('checkout.api.v1.staff.sessions.index'))->toBeFalse();

    $this->postJson('/api/v1/checkout/sessions', [
        'currency' => 'GBP',
        'lines' => [['name' => 'Rain Coat', 'unit_price_minor' => 1999]],
    ])->assertNotFound();

    $this->getJson('/api/v1/staff/checkout/sessions')->assertNotFound();
});
