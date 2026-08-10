<?php

use Illuminate\Testing\TestResponse;
use Liberu\Ecommerce\Checkout\Api\Tests\NoGroupsTestCase;
use Liberu\Ecommerce\Checkout\Api\Tests\ShopperOnlyTestCase;
use Liberu\Ecommerce\Checkout\Api\Tests\TestCase;
use Liberu\Ecommerce\Checkout\Api\Tests\TokenActor;
use Liberu\PackageTestbench\TestUser;
use Liberu\PackageTestbench\UsesTestUser;

uses(TestCase::class, UsesTestUser::class)->in(__DIR__.'/Feature');

/*
 * Its own directory rather than its own `uses()` line: Pest binds one test case
 * per folder, and a file claiming a second one inside a folder that already has
 * one is a hard error.
 */
uses(ShopperOnlyTestCase::class, UsesTestUser::class)->in(__DIR__.'/OptIn');

uses(NoGroupsTestCase::class, UsesTestUser::class)->in(__DIR__.'/None');

/**
 * An actor working in a team.
 *
 * `current_team_id` is set in memory rather than migrated onto the users table:
 * the policy reads it as a property and nothing here persists the user again,
 * so a column would be a fixture pretending to be a schema.
 */
function actor(?int $teamId = 7): TestUser
{
    $user = TestUser::factory()->create();
    $user->current_team_id = $teamId;

    return $user;
}

/**
 * The same actor, holding a token that grants exactly these scopes.
 *
 * Created through {@see TestUser}'s factory and re-read as the subclass,
 * because the factory builds the model it is bound to and the two share a
 * table. What matters to the middleware is the `tokenCan()` answer, not the
 * class.
 *
 * @param  list<string>  $abilities
 */
function tokenActor(array $abilities, ?int $teamId = 7): TokenActor
{
    $actor = TokenActor::query()->findOrFail(TestUser::factory()->create()->getKey());
    $actor->current_team_id = $teamId;
    $actor->abilities = $abilities;

    return $actor;
}

/*
 * Every fixture in this suite is built through the API itself rather than
 * through the domain's factories.
 *
 * That is deliberate for an adapter: a session built by a `POST` is a session a
 * client can actually reach, and a fixture that a factory forced into a shape
 * no request can produce would prove nothing about the transport. It also keeps
 * the boundary honest — nothing here needs a model class either.
 */

/** The base path both groups hang off. */
function api(string $path = ''): string
{
    return '/api/v1'.$path;
}

/** @param array<string, mixed> $overrides */
function startSession(array $overrides = []): string
{
    $payload = array_replace([
        'currency' => 'GBP',
        'email' => 'shopper@example.test',
        'lines' => [[
            'name' => 'Rain Coat',
            'unit_price_minor' => 1999,
            'quantity' => 1,
            'tax_rate_bp' => 2000,
        ]],
    ], $overrides);

    return (string) test()->postJson(api('/checkout/sessions'), $payload)
        ->assertCreated()
        ->json('data.token');
}

function readSession(string $token): array
{
    return (array) test()->getJson(api('/checkout/sessions/'.$token))->assertOk()->json('data');
}

function grandTotal(string $token): int
{
    return (int) readSession($token)['totals']['grand_total']['minor'];
}

/** A tender that meets the grand total exactly, which is the only kind that places. */
function tenderInFull(string $token, ?int $amount = null): void
{
    test()->postJson(api('/checkout/sessions/'.$token.'/tenders'), [
        'amount_minor' => $amount ?? grandTotal($token),
        'status' => 'captured',
        'provider' => 'the-host-named-this',
        'reference' => 'ref_123',
    ])->assertCreated();
}

/** A session that would place: lines, an email and the money to match. */
function placeable(array $overrides = []): string
{
    $token = startSession($overrides);

    tenderInFull($token);

    return $token;
}

/** @param array<string, mixed> $body */
function place(string $token, ?string $key = 'idem_key_one', array $body = []): TestResponse
{
    return test()->postJson(
        api('/checkout/sessions/'.$token.'/placement'),
        $body,
        $key === null ? [] : ['Idempotency-Key' => $key],
    );
}
