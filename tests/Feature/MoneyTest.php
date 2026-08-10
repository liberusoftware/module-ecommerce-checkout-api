<?php

/*
 * Every amount this package publishes, in one shape.
 *
 * `{"minor": 1999, "currency": "GBP", "exponent": 2, "decimal": "19.99"}`, and
 * `decimal` is a **string**. Both halves of that matter.
 *
 * The minor units are the authority — integers, no float, no `decimal` column
 * anywhere in the fleet. The exponent travels with them because a consumer
 * handed `1999` cannot render it without knowing where the point goes, and the
 * one place that knowledge reliably goes missing is a client written by
 * somebody else against a document that did not say.
 *
 * And `decimal` is a string because a JSON number is a float in most parsers
 * and `19.99` does not exist as one. A consumer that reads the string gets the
 * right answer; one that reads the integer gets the right answer; there is no
 * third path where a rounding error is introduced by the transport.
 */

it('publishes every amount as an object carrying its own exponent', function () {
    $token = startSession(['lines' => [
        ['name' => 'Rain Coat', 'unit_price_minor' => 1999, 'quantity' => 1, 'tax_rate_bp' => 2000],
    ]]);

    $session = readSession($token);

    foreach ($session['totals'] as $money) {
        expect($money)->toHaveKeys(['minor', 'currency', 'exponent', 'decimal'])
            ->and($money['minor'])->toBeInt()
            ->and($money['exponent'])->toBeInt()
            ->and($money['decimal'])->toBeString();
    }

    expect($session['totals']['subtotal'])->toBe(['minor' => 1999, 'currency' => 'GBP', 'exponent' => 2, 'decimal' => '19.99'])
        ->and($session['lines'][0]['unit_price']['decimal'])->toBe('19.99')
        ->and($session['lines'][0]['tax']['decimal'])->toBe('4.00')
        ->and($session['lines'][0]['gross']['decimal'])->toBe('23.99');
});

it('is right about the one conversion everybody gets wrong', function () {
    // `(int) (19.99 * 100)` is 1998. Nothing in this fleet converts that way —
    // the string arithmetic lives in the domain's `MinorUnits`, and this is the
    // assertion that the API's own rendering round-trips through it rather than
    // through a float.
    expect((int) (19.99 * 100))->toBe(1998);

    $token = startSession(['lines' => [
        ['name' => 'Rain Coat', 'unit_price_minor' => 1999, 'taxable' => false],
    ]]);

    expect(readSession($token)['totals']['grand_total'])
        ->toBe(['minor' => 1999, 'currency' => 'GBP', 'exponent' => 2, 'decimal' => '19.99']);
});

it('carries the session\'s own exponent rather than assuming two', function () {
    // A zero-exponent currency. 250 yen is 250, not 2.50, and a client that
    // divided by a hard-coded 100 would show a hundredth of the price.
    $token = startSession([
        'currency' => 'JPY',
        'exponent' => 0,
        'lines' => [['name' => 'Rain Coat', 'unit_price_minor' => 250, 'taxable' => false]],
    ]);

    expect(readSession($token)['totals']['grand_total'])
        ->toBe(['minor' => 250, 'currency' => 'JPY', 'exponent' => 0, 'decimal' => '250']);
});

it('renders the same shape on a placement as on a session', function () {
    $token = placeable();

    $placed = place($token)->assertOk()->json('data');

    expect($placed['totals']['grand_total'])->toHaveKeys(['minor', 'currency', 'exponent', 'decimal'])
        ->and($placed['totals']['grand_total']['decimal'])->toBe('23.99')
        ->and($placed['lines'][0]['gross'])->toHaveKeys(['minor', 'currency', 'exponent', 'decimal'])
        ->and($placed['tenders'][0]['amount']['decimal'])->toBe('23.99');
});

it('publishes no row ids on any surface', function () {
    // The domain has no lookup by id on purpose — an incrementing id in a URL
    // is an enumeration of everybody's checkouts — and an id in a response body
    // is the same number one request later. The token is the handle.
    $token = placeable();
    $placed = place($token)->assertOk()->json('data');

    expect(readSession($token))->not->toHaveKey('id')
        ->and($placed)->not->toHaveKey('id')
        ->and($placed)->not->toHaveKey('checkout_session_id');
});
