<?php

use Illuminate\Support\Facades\DB;

/*
 * Consent is evidence, and evidence has a chain of custody.
 *
 * Four facts, because those are the four a lawyer asks for and a boolean
 * answers none of them: what was agreed, which version of the text, when, and
 * from where. `document_version` is the one people leave out and the one that
 * matters most — "they accepted the terms" is worthless if nobody can say which
 * terms were on the screen.
 *
 * The two facts this transport contributes are the IP address and the user
 * agent, because it is the only layer that has a request. What it does with
 * them afterwards is the subject of half this file: they go into the consent
 * row and nowhere else. Not into the response, not into the log line, not into
 * any other surface this package publishes.
 */

it('captures the agreement, the version, the moment and where it came from', function () {
    $token = startSession();

    $this->withHeaders(['User-Agent' => 'CheckoutTest/1.0'])
        ->postJson(api('/checkout/sessions/'.$token.'/consents'), [
            'type' => 'terms',
            'document_version' => '2026-01-14',
            'document_url' => 'https://example.test/terms/2026-01-14',
        ])
        ->assertCreated();

    $consent = DB::table('ecommerce_checkout_consents')->first();

    expect($consent->type)->toBe('terms')
        ->and($consent->document_version)->toBe('2026-01-14')
        ->and((bool) $consent->agreed)->toBeTrue()
        ->and($consent->agreed_at)->not->toBeNull()
        ->and($consent->ip_address)->not->toBeNull()
        ->and($consent->user_agent)->toBe('CheckoutTest/1.0');
});

it('never hands the evidence back out', function () {
    $token = startSession();

    $response = $this->withHeaders(['User-Agent' => 'CheckoutTest/1.0'])
        ->postJson(api('/checkout/sessions/'.$token.'/consents'), [
            'type' => 'terms',
            'document_version' => '2026-01-14',
        ])
        ->assertCreated();

    $consent = $response->json('data.consents.0');

    // Present, so a client can render "you agreed to v2026-01-14".
    expect($consent)->toHaveKeys(['type', 'document_version', 'agreed', 'agreed_at', 'document_url'])
        // Absent, on every surface. These were collected because a regulator
        // asks who agreed and from where; echoing them onto a surface reachable
        // with a bearer token turns a legal record into a disclosure.
        ->and($consent)->not->toHaveKey('ip_address')
        ->and($consent)->not->toHaveKey('user_agent');

    expect($response->getContent())->not->toContain('CheckoutTest/1.0');

    // And the same on the staff read, which is the surface an operator sees.
    expect($this->actingAs(actor(7))->getJson(api('/staff/checkout/sessions/'.$token))->assertOk()->getContent())
        ->not->toContain('CheckoutTest/1.0');
});

it('records a refusal as a first-class answer', function () {
    $token = startSession();

    $response = $this->postJson(api('/checkout/sessions/'.$token.'/consents'), [
        'type' => 'marketing',
        'document_version' => 'v3',
        'agreed' => false,
    ])->assertCreated();

    // A shopper who declined marketing has said something, and a missing row
    // cannot tell that apart from a form that never rendered.
    expect($response->json('data.consents.0.agreed'))->toBeFalse();
});

it('does not fabricate a second agreement when a form is submitted twice', function () {
    $token = startSession();

    foreach ([1, 2] as $ignored) {
        $this->postJson(api('/checkout/sessions/'.$token.'/consents'), [
            'type' => 'terms',
            'document_version' => 'v1',
        ])->assertCreated();
    }

    expect(readSession($token)['consents'])->toHaveCount(1);

    // A text that changes mid-session produces a second row rather than
    // overwriting the first, so the record of what was shown first survives.
    $this->postJson(api('/checkout/sessions/'.$token.'/consents'), [
        'type' => 'terms',
        'document_version' => 'v2',
    ])->assertCreated();

    expect(readSession($token)['consents'])->toHaveCount(2);
});

it('requires the two facts that make a consent worth keeping', function () {
    $token = startSession();

    $this->postJson(api('/checkout/sessions/'.$token.'/consents'), ['type' => 'terms'])->assertStatus(422);
    $this->postJson(api('/checkout/sessions/'.$token.'/consents'), ['document_version' => 'v1'])->assertStatus(422);
});
