<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Checkout\Api\Tests;

use Liberu\PackageTestbench\TestUser;

/**
 * An actor whose token carries a scope list.
 *
 * This package depends on neither Sanctum nor Passport and must not: the scope
 * check asks whatever the host's actor answers to `tokenCan()`, which is the
 * method both of them publish. Standing in for them here is a class that
 * answers the same question from a list a test can set — proving the check
 * reads the actor's own answer, rather than proving Sanctum works.
 */
class TokenActor extends TestUser
{
    /** @var list<string> */
    public array $abilities = [];

    public function tokenCan(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }
}
