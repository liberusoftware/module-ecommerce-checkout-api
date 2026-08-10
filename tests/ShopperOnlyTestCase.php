<?php

namespace Liberu\Ecommerce\Checkout\Api\Tests;

/**
 * A deployment that publishes a checkout and nothing else.
 *
 * The most likely composition in the fleet, and the one worth pinning: a host
 * with its own admin panel wants the shopper surface and no staff reads, and
 * those reads are then not merely denied — they are not registered, so there is
 * no surface to find a hole in.
 *
 * Not `final`: Pest subclasses the case it binds to a directory.
 */
class ShopperOnlyTestCase extends TestCase
{
    /** @return list<string> */
    protected function enabledGroups(): array
    {
        return ['shopper'];
    }
}
