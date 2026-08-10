<?php

namespace Liberu\Ecommerce\Checkout\Api\Tests;

/**
 * A deployment that installed the package and named no group — which is the
 * shape every deployment starts in, because `checkout-api.groups` is empty in
 * the shipped config.
 *
 * Not `final`: Pest subclasses the case it binds to a directory.
 */
class NoGroupsTestCase extends TestCase
{
    /** @return list<string> */
    protected function enabledGroups(): array
    {
        return [];
    }
}
