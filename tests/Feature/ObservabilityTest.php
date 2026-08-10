<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

/**
 * Everything logged during the closure, in order.
 *
 * `Log::listen` rather than a mocked facade: it observes the real logger through
 * the real channel, so a change that stops the line reaching the host's pipeline
 * fails here rather than passing against a double.
 *
 * A long closure with `use (&$captured)` rather than an arrow function, because
 * an arrow function captures by value at definition and would hand back the
 * empty array it started with.
 *
 * @return list<MessageLogged>
 */
function captureLogs(Closure $work): array
{
    $captured = [];

    Log::listen(function (MessageLogged $message) use (&$captured): void {
        $captured[] = $message;
    });

    $work();

    return $captured;
}

it('logs one structured line per operation', function () {
    startSession();
    $actor = actor(7);

    $captured = captureLogs(fn () => $this->actingAs($actor)
        ->getJson(api('/staff/checkout/sessions'))
        ->assertOk());

    expect($captured)->toHaveCount(1);

    $context = $captured[0]->context;

    expect($captured[0]->message)->toBe('checkout.api')
        ->and($context['operation'])->toBe('checkout.api.v1.staff.sessions.index')
        ->and($context['actor_id'])->toBe($actor->getKey())
        ->and($context['method'])->toBe('GET')
        ->and($context['status'])->toBe(200)
        ->and($context['outcome'])->toBe('success')
        ->and($context['request_id'])->toBeString()->not->toBeEmpty();
});

/*
 * The test this file exists for.
 *
 * The session token is a bearer credential and the only handle a guest has on
 * their own checkout. A token in a log aggregator is a working key to somebody's
 * basket, held by everybody with log access, for the length of the retention
 * policy. The `Idempotency-Key` is the same kind of thing. So the logged path is
 * the route's *pattern* and never the URL as it arrived.
 */
it('never writes the token or the idempotency key into the log', function () {
    $token = placeable();

    $captured = captureLogs(fn () => place($token, 'secret_key_material')->assertOk());

    $encoded = json_encode(array_map(fn (MessageLogged $m): array => ['message' => $m->message, 'context' => $m->context], $captured));

    expect($encoded)->not->toContain($token);
    expect($encoded)->not->toContain('secret_key_material');

    // The route pattern is there instead, which is what an operator actually
    // wants to group by.
    expect($captured[0]->context['route'])->toBe('api/v1/checkout/sessions/{token}/placement');
});

it('never writes the consent evidence into the log', function () {
    $token = startSession();

    $captured = captureLogs(fn () => $this->withHeaders(['User-Agent' => 'CheckoutTest/1.0'])
        ->postJson(api('/checkout/sessions/'.$token.'/consents'), ['type' => 'terms', 'document_version' => 'v1'])
        ->assertCreated());

    $encoded = json_encode(array_map(fn (MessageLogged $m): array => ['message' => $m->message, 'context' => $m->context], $captured));

    // Collected because a regulator asks who agreed and from where. A log line
    // is a second purpose nobody consented to.
    expect($encoded)->not->toContain('CheckoutTest/1.0');
});

it('records a refusal as one, rather than dropping it', function () {
    $token = startSession();

    $captured = captureLogs(fn () => $this->actingAs(actor(9))
        ->getJson(api('/staff/checkout/sessions/'.$token))
        ->assertForbidden());

    expect($captured[0]->context['status'])->toBe(403)
        ->and($captured[0]->context['outcome'])->toBe('failure');
});

it('records the idempotency refusals, which are the ones an operator chases', function () {
    $first = placeable();
    $second = placeable();

    place($first, 'shared')->assertOk();

    $captured = captureLogs(fn () => place($second, 'shared')->assertStatus(409));

    expect($captured[0]->context['status'])->toBe(409)
        ->and($captured[0]->context['outcome'])->toBe('failure')
        ->and($captured[0]->context['operation'])->toBe('checkout.api.v1.shopper.placement.store');
});

it('logs an anonymous shopper request with a null actor', function () {
    $captured = captureLogs(fn () => $this->postJson(api('/checkout/sessions'), [
        'currency' => 'GBP',
        'lines' => [['name' => 'Rain Coat', 'unit_price_minor' => 1999]],
    ])->assertCreated());

    expect($captured)->toHaveCount(1)
        ->and($captured[0]->context['operation'])->toBe('checkout.api.v1.shopper.sessions.store')
        ->and($captured[0]->context['actor_id'])->toBeNull();
});

it('carries the caller’s request id through to the log and the response', function () {
    $captured = captureLogs(function () {
        $this->withHeader('X-Request-Id', 'trace-abc')
            ->postJson(api('/checkout/sessions'), [
                'currency' => 'GBP',
                'lines' => [['name' => 'Rain Coat', 'unit_price_minor' => 1999]],
            ])
            ->assertCreated()
            ->assertHeader('X-Request-Id', 'trace-abc');
    });

    expect($captured[0]->context['request_id'])->toBe('trace-abc');
});

it('mints a request id when the caller sent none', function () {
    $response = $this->postJson(api('/checkout/sessions'), [
        'currency' => 'GBP',
        'lines' => [['name' => 'Rain Coat', 'unit_price_minor' => 1999]],
    ])->assertCreated();

    expect($response->headers->get('X-Request-Id'))->toBeString()->not->toBeEmpty();
});
