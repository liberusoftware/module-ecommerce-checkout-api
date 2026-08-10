<?php

namespace Liberu\Ecommerce\Checkout\Api\Tests;

use Liberu\Ecommerce\Checkout\CheckoutServiceProvider;
use Liberu\PackageTestbench\PackageTestCase;
use Monolog\Handler\NullHandler;

/**
 * Boots the domain module alongside this one.
 *
 * `PackageTestCase` finds sibling providers through `extra.laravel.providers`
 * and through `require-dev` manifests. Neither reaches a module declared in
 * `require`, and by design: a runtime requirement is not a statement that the
 * module should boot. Here it has to — this package is an adapter over that
 * module's actions, queries and policy, and without it there is nothing to
 * adapt.
 *
 * Both route groups are enabled, because the interesting question in almost
 * every test is what an endpoint does rather than whether it exists. The one
 * test about opt-in brings its own case.
 *
 * **No cart module is installed and none is required.** Every session in this
 * suite is started from lines handed in over HTTP, which is the only way this
 * package knows how to start one.
 */
abstract class TestCase extends PackageTestCase
{
    /** @return list<string> */
    protected function enabledGroups(): array
    {
        return ['shopper', 'staff'];
    }

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return array_values(array_unique([
            CheckoutServiceProvider::class,
            ...parent::getPackageProviders($app),
        ]));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('checkout-api.groups', $this->enabledGroups());

        // A configured single-tenant deployment, which is the composition the
        // staff group needs: `CheckoutSessionPolicy` matches on `team_id`, so a
        // session filed under nobody is visible to no staff actor at all.
        $app['config']->set('checkout-api.tenant.team_id', 7);
        $app['config']->set('checkout-api.tenant.store_id', 1);

        // The rate limiter needs a cache, and an in-memory one is the only kind
        // that starts empty for every test.
        $app['config']->set('cache.default', 'array');

        // A null log channel still fires `MessageLogged`, so the observability
        // test sees every line while nothing writes a file into a skeleton
        // application that exists for the length of one test.
        $app['config']->set('logging.default', 'null');
        $app['config']->set('logging.channels.null', ['driver' => 'monolog', 'handler' => NullHandler::class]);
    }
}
