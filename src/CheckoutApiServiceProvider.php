<?php

namespace Liberu\Ecommerce\Checkout\Api;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\Checkout\Api\Http\Controllers\ShopperController;
use Liberu\Ecommerce\Checkout\Api\Http\Controllers\StaffController;
use Liberu\Ecommerce\Checkout\Api\Http\Middleware\EnforceScope;
use Liberu\Ecommerce\Checkout\Api\Http\Middleware\LogOperation;

/**
 * Registered by `ModuleManagerServiceProvider` from `module.json`, never by
 * Composer discovery — this package ships no `extra.laravel.providers`, so
 * installing it boots nothing until the deployment names the module in
 * `MODULES_ENABLED`.
 *
 * Enablement is only the first of two decisions. An enabled module still
 * publishes no routes until `checkout-api.groups` names some: an API that can
 * take a payment instruction is a commitment, and a package that starts
 * accepting one because Composer ran has made that commitment on the operator's
 * behalf.
 *
 * The shopper group is registered in its own `Route::group` because it is the
 * one group whose credential is different: it needs no actor, only the token in
 * the path. Everything else about it is the same — the same log line, the same
 * limiter, the same scope check.
 */
class CheckoutApiServiceProvider extends ServiceProvider
{
    /**
     * What a session token can look like.
     *
     * `StartCheckout` mints one with `Str::random(48)`, which is alphanumeric.
     * Constraining the segment means a URL carrying anything else is a 404 from
     * the router rather than a query, so a scanner walking the path space never
     * reaches the database at all.
     */
    private const TOKEN = '[A-Za-z0-9]{20,64}';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/checkout-api.php', 'checkout-api');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/checkout-api.php' => config_path('checkout-api.php'),
        ], 'checkout-api-config');

        /** @var list<string> $groups */
        $groups = (array) config('checkout-api.groups', []);

        if ($groups === []) {
            return;
        }

        if (in_array('shopper', $groups, true)) {
            $this->group('shopper_middleware', $this->shopperRoutes(...));
        }

        if (in_array('staff', $groups, true)) {
            $this->group('middleware', $this->staffRoutes(...));
        }
    }

    private function group(string $guard, callable $routes): void
    {
        $version = (string) config('checkout-api.version', 'v1');

        Route::prefix(trim((string) config('checkout-api.prefix', 'api'), '/').'/'.$version)
            ->middleware($this->middleware($guard))
            ->name('checkout.api.'.$version.'.')
            ->group($routes);
    }

    /**
     * What every route in a group runs through, in order.
     *
     * The logger is first so that it also records what the host's guard turns
     * away; the host's own middleware next, because everything after it wants an
     * actor; the limiter after that, so a refusal is cheap and per-actor; and the
     * scope check last, because narrowing a token is only meaningful once the
     * request is going to be answered at all.
     *
     * @return array<int, mixed>
     */
    private function middleware(string $guard): array
    {
        $limiter = trim((string) config('checkout-api.rate_limit.limiter', ''));

        // Only if nothing else has claimed the name. A deployment with an opinion
        // registers its own and this leaves it alone — the alternative is a
        // package overwriting the host's rate limiting because it booted later.
        if ($limiter !== '' && RateLimiter::limiter($limiter) === null) {
            RateLimiter::for($limiter, fn (Request $request): Limit => Limit::perMinute((int) config('checkout-api.rate_limit.per_minute', 60))
                ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        }

        return [
            LogOperation::class,
            ...array_values((array) config('checkout-api.'.$guard, [])),
            ...($limiter === '' ? [] : ['throttle:'.$limiter]),
            EnforceScope::class,
        ];
    }

    /**
     * The shopper's own checkout.
     *
     * Every path after the first names the session's token, and it is a path
     * segment rather than a header because it is the address of the resource
     * rather than a credential for a wider surface — there is no other checkout
     * this request could be about. The domain publishes no lookup by id for the
     * same reason: an incrementing id in a URL is an enumeration of everybody's
     * checkouts.
     *
     * `POST .../placement` rather than `POST .../place`: the thing being created
     * is the placement, and its `Idempotency-Key` names *that attempt to create
     * that placement*. A verb in the path would suggest a command that could be
     * issued twice, which is exactly the reading the header exists to forbid.
     */
    private function shopperRoutes(): void
    {
        Route::post('checkout/sessions', [ShopperController::class, 'start'])
            ->name('shopper.sessions.store');

        Route::get('checkout/sessions/{token}', [ShopperController::class, 'show'])
            ->name('shopper.sessions.show')->where('token', self::TOKEN);

        Route::put('checkout/sessions/{token}/contact', [ShopperController::class, 'contact'])
            ->name('shopper.sessions.contact')->where('token', self::TOKEN);

        Route::put('checkout/sessions/{token}/discount', [ShopperController::class, 'discount'])
            ->name('shopper.sessions.discount')->where('token', self::TOKEN);

        Route::post('checkout/sessions/{token}/consents', [ShopperController::class, 'consent'])
            ->name('shopper.consents.store')->where('token', self::TOKEN);

        Route::post('checkout/sessions/{token}/tenders', [ShopperController::class, 'tender'])
            ->name('shopper.tenders.store')->where('token', self::TOKEN);

        Route::post('checkout/sessions/{token}/placement', [ShopperController::class, 'place'])
            ->name('shopper.placement.store')->where('token', self::TOKEN);

        Route::delete('checkout/sessions/{token}', [ShopperController::class, 'abandon'])
            ->name('shopper.sessions.destroy')->where('token', self::TOKEN);
    }

    /** The operator's surface: read only, and only what the policy admits. */
    private function staffRoutes(): void
    {
        Route::get('staff/checkout/sessions', [StaffController::class, 'index'])
            ->name('staff.sessions.index');

        Route::get('staff/checkout/sessions/{token}', [StaffController::class, 'show'])
            ->name('staff.sessions.show')->where('token', self::TOKEN);
    }
}
