<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Liberu\Ecommerce\Checkout\Api\Http\Scopes;

/*
 * The document and the routes, held against each other.
 *
 * A hand-written specification with no test like this is a specification that is
 * wrong within a month — the drift is silent, and the first person to notice is
 * a consumer who wrote a client against it. So every registered route must have
 * an operation and every operation must have a route, in both directions, and
 * the scope an operation advertises must be the scope the middleware actually
 * enforces. A documented control that does not exist is worse than an absent one.
 */

/** @return array<string, mixed> */
function document(): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/openapi/checkout.json'), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

/**
 * Every registered operation, as `method /path`, mapped to its route name.
 *
 * The prefix is stripped because the document's paths are relative to the
 * `servers` entry, which is where the configurable prefix and version live.
 *
 * @return array<string, string>
 */
function registeredOperations(): array
{
    $prefix = config('checkout-api.prefix').'/'.config('checkout-api.version').'/';
    $operations = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($name, 'checkout.api.')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            // Laravel registers HEAD alongside every GET. The document
            // describes GET, and OpenAPI says HEAD follows it.
            if ($method === 'HEAD') {
                continue;
            }

            $operations[strtolower($method).' /'.Str::after($route->uri(), $prefix)] = $name;
        }
    }

    return $operations;
}

/**
 * An operation's documented statuses, as strings.
 *
 * Cast back deliberately: a JSON object keyed `"423"` decodes into a PHP array
 * keyed by the integer 423, and comparing those against the codes as written
 * would fail for reasons that have nothing to do with the document.
 *
 * @param  array<string, mixed>  $operation
 * @return list<string>
 */
function statusesOf(array $operation): array
{
    return array_map(strval(...), array_keys($operation['responses']));
}

it('describes every route it registers, and registers every route it describes', function () {
    $described = [];

    foreach (document()['paths'] as $path => $methods) {
        foreach (array_keys($methods) as $method) {
            $described[] = $method.' '.$path;
        }
    }

    $registered = array_keys(registeredOperations());

    sort($described);
    sort($registered);

    expect($described)->toBe($registered);
});

it('gives every operation a stable, unique identifier', function () {
    $ids = [];

    foreach (document()['paths'] as $methods) {
        foreach ($methods as $operation) {
            expect($operation['operationId'])->toBeString()->not->toBeEmpty();
            $ids[] = $operation['operationId'];
        }
    }

    expect($ids)->toHaveCount(count(array_unique($ids)));
});

it('carries deprecation metadata on every operation before anything is deprecated', function () {
    foreach (document()['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            expect($operation['deprecated'])->toBeBool("{$method} {$path} declares no deprecated flag")
                ->and($operation['x-liberu-deprecation'])->toHaveKeys(['since', 'sunset', 'successor']);
        }
    }
});

it('advertises exactly the scope the middleware enforces', function () {
    $routes = registeredOperations();

    foreach (document()['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            $advertised = $operation['security'][0]['checkoutToken'];
            $enforced = Scopes::forRoute($routes[$method.' '.$path], $method);

            expect($advertised)->toBe([$enforced], "{$method} {$path} advertises a scope it does not enforce");
        }
    }
});

it('names only scopes the security scheme declares', function () {
    $declared = array_keys(document()['components']['securitySchemes']['checkoutToken']['flows']['clientCredentials']['scopes']);

    expect($declared)->toBe(Scopes::ALL);
});

it('tags every operation with the route group that has to be opted in for it to exist', function () {
    $routes = registeredOperations();

    foreach (document()['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            expect($operation['x-liberu-route-group'])
                ->toBeIn(['shopper', 'staff'])
                ->and($routes[$method.' '.$path])->toContain('.'.$operation['x-liberu-route-group'].'.');
        }
    }
});

/*
 * The one piece of documentation this package cannot afford to get wrong. An
 * operation in the shopper group is an operation reachable with nothing but a
 * token, and a consumer reading the document has to be able to tell which those
 * are without running the code.
 */
it('marks every shopper operation as one that needs no actor, and no other', function () {
    $routes = registeredOperations();

    foreach (document()['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            $shopper = str_contains($routes[$method.' '.$path], '.shopper.');

            expect($operation['x-liberu-anonymous'])->toBe($shopper, "{$method} {$path} misstates whether it needs an actor");
        }
    }
});

it('is an OpenAPI 3.1 document at the version this package ships', function () {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/module.json'), true, 512, JSON_THROW_ON_ERROR);

    expect(document()['openapi'])->toStartWith('3.1.')
        ->and(document()['info']['version'])->toBe($manifest['version']);
});

it('describes a response for every status this transport can answer', function () {
    foreach (document()['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            $statuses = statusesOf($operation);

            // `toContain` is variadic, so a message passed to it becomes
            // another value it looks for. The membership test carries the
            // message instead.
            expect(in_array('429', $statuses, true))
                ->toBeTrue("{$method} {$path} does not document the rate limiter's refusal");

            // A shopper operation has no actor to reject, so it documents no
            // 401 — and everything else must, because the base controller
            // answers 401 whether or not the host configured a guard.
            $expects401 = $operation['x-liberu-route-group'] !== 'shopper';

            expect(in_array('401', $statuses, true))->toBe($expects401, "{$method} {$path} misstates its 401");
        }
    }
});

/*
 * The operation this package exists for, and the four answers a client has to
 * be able to write code against before it ever sends a request.
 */
it('documents every idempotency outcome on the placement operation', function () {
    $placement = document()['paths']['/checkout/sessions/{token}/placement']['post'];

    $statuses = statusesOf($placement);

    foreach (['200', '400', '409', '422', '423'] as $status) {
        expect(in_array($status, $statuses, true))
            ->toBeTrue("the placement operation does not document its {$status}");
    }

    // The two conditions the domain raises one exception class for get two
    // statuses here. A document that collapsed them would be telling a client
    // to give up on a commit that is at that instant succeeding.
    expect($placement['responses']['409']['$ref'])->not->toBe($placement['responses']['423']['$ref']);

    // Replay is signalled on the response rather than by a second status.
    expect($placement['responses']['200']['headers'])->toHaveKey('Idempotency-Replayed');

    // And the key is required, not optional — a server-minted one would be a
    // new key on every retry.
    $key = collect($placement['parameters'])
        ->map(fn (array $parameter): array => data_get(document(), str_replace(['#/', '/'], ['', '.'], (string) $parameter['$ref']), []))
        ->firstWhere('name', 'Idempotency-Key');

    expect($key)->not->toBeNull()
        ->and($key['in'])->toBe('header')
        ->and($key['required'])->toBeTrue();
});

/*
 * The evidence rule, stated in the document as well as enforced in the code. A
 * consumer must not write a client that expects to read back an IP address it
 * sent, and an auditor must be able to see that it never comes back.
 */
it('publishes no consent evidence field on any schema', function () {
    $consent = document()['components']['schemas']['Consent'];

    expect(array_keys($consent['properties']))->toBe(['type', 'document_version', 'document_url', 'agreed', 'agreed_at'])
        ->and($consent['additionalProperties'])->toBeFalse();

    expect(json_encode(document()['components']['schemas']))->not->toContain('ip_address');
});

it('publishes money in exactly one shape', function () {
    $money = document()['components']['schemas']['Money'];

    expect($money['required'])->toBe(['minor', 'currency', 'exponent', 'decimal'])
        ->and($money['properties']['minor']['type'])->toBe('integer')
        // A string, because a JSON number is a float in most parsers and 19.99
        // does not exist as one.
        ->and($money['properties']['decimal']['type'])->toBe('string')
        ->and($money['additionalProperties'])->toBeFalse();
});
