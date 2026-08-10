<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Checkout\Api\Http\Resources;

use Liberu\Ecommerce\Checkout\Data\CheckoutSessionData;
use Liberu\Ecommerce\Checkout\Data\ConsentData;
use Liberu\Ecommerce\Checkout\Data\LineData;
use Liberu\Ecommerce\Checkout\Data\Money;
use Liberu\Ecommerce\Checkout\Data\PlacedCheckout;
use Liberu\Ecommerce\Checkout\Data\TenderData;

/**
 * The domain's read models, as this transport publishes them.
 *
 * A thin layer that would be tempting to skip — the read models already
 * serialise themselves — and three differences make it worth its file.
 *
 * **Money is an object here.** The domain's arrays carry `*_minor` integers,
 * which is the right storage and the wrong wire format: a consumer handed
 * `1999` has to know the currency's exponent before it can render a price, and
 * the one place that knowledge reliably goes missing is a client written by
 * somebody else. So every amount goes out as
 * `{"minor": 1999, "currency": "GBP", "exponent": 2, "decimal": "19.99"}`, with
 * `decimal` a **string** because a JSON number is a float in most parsers and
 * `19.99` does not exist as one.
 *
 * **The consent evidence does not come back.** `ip_address` and `user_agent`
 * are captured because a regulator asks who agreed and from where; they are not
 * part of a checkout's presentation, and echoing them onto a surface reachable
 * with a token turns a legal record into a disclosure. They stay in the
 * database, where a panel or a subject-access request reaches them under an
 * actor's authority. Nothing here and nothing in the log line carries them.
 *
 * **No row ids.** The domain publishes no lookup by id on purpose — an
 * incrementing id in a URL is an enumeration of everybody's checkouts — and an
 * id in a response body is the same number one HTTP request later. The token is
 * the handle, and for a placement the idempotency key is the correlation.
 */
final class Wire
{
    /** @return array<string, mixed> */
    public static function session(CheckoutSessionData $session): array
    {
        return [
            'token' => $session->token,
            'status' => $session->status->value,
            'currency' => $session->currency,
            'exponent' => $session->currencyExponent,
            'email' => $session->email,
            'customer_id' => $session->customerId,
            'shipping_address' => $session->shippingAddress,
            'billing_address' => $session->billingAddress,
            'totals' => array_map(self::money(...), $session->totals()),
            'lines' => array_map(self::line(...), $session->lines),
            'tenders' => array_map(self::tender(...), $session->tenders),
            'consents' => array_map(self::consent(...), $session->consents),
            'expires_at' => $session->expiresAt,
            'placed_at' => $session->placedAt,
        ];
    }

    /**
     * The frozen record of a commit.
     *
     * Deliberately a different shape from a session rather than a session with
     * `status: placed`. A client reading this has finished — there is no
     * `expires_at` left to watch, no discount left to change — and the two
     * fields it gains, `idempotency_key` and `placed_at`, are the two facts a
     * retry needs to recognise its own receipt.
     *
     * @return array<string, mixed>
     */
    public static function placed(PlacedCheckout $checkout): array
    {
        $money = fn (int $minor): array => self::money(new Money($minor, $checkout->currency, $checkout->currencyExponent));

        return [
            'token' => $checkout->token,
            'currency' => $checkout->currency,
            'exponent' => $checkout->currencyExponent,
            'email' => $checkout->email,
            'customer_id' => $checkout->customerId,
            'shipping_address' => $checkout->shippingAddress,
            'billing_address' => $checkout->billingAddress,
            'totals' => [
                'subtotal' => $money($checkout->subtotalMinor),
                'discount' => $money($checkout->discountMinor),
                'net' => $money($checkout->netMinor),
                'tax' => $money($checkout->taxMinor),
                'grand_total' => $money($checkout->grandTotalMinor),
            ],
            'lines' => array_map(self::line(...), $checkout->lines),
            'tenders' => array_map(self::tender(...), $checkout->tenders),
            'consents' => array_map(self::consent(...), $checkout->consents),
            'idempotency_key' => $checkout->idempotencyKey,
            'placed_at' => $checkout->placedAt,
        ];
    }

    /** @return array<string, mixed> */
    public static function line(LineData $line): array
    {
        $money = fn (int $minor): array => self::money(new Money($minor, $line->currency, $line->currencyExponent));

        return [
            'kind' => $line->kind->value,
            'name' => $line->name,
            'sku' => $line->sku,
            'quantity' => $line->quantity,
            'product_id' => $line->productId,
            'variant_id' => $line->variantId,
            'taxable' => $line->taxable,
            'tax_rate_bp' => $line->taxRateBp,
            'position' => $line->position,
            'unit_price' => $money($line->unitPriceMinor),
            'subtotal' => $money($line->subtotalMinor),
            'discount' => $money($line->discountMinor),
            'net' => $money($line->netMinor),
            'tax' => $money($line->taxMinor),
            'gross' => $money($line->grossMinor),
            'metadata' => $line->metadata,
        ];
    }

    /**
     * What a provider reported, with no provider named by this package.
     *
     * `provider` is a string the host chose and neither the domain nor this
     * transport interprets. There is no gateway here, no SDK, and no brand name
     * anywhere in `src/`.
     *
     * @return array<string, mixed>
     */
    public static function tender(TenderData $tender): array
    {
        return [
            'kind' => $tender->kind->value,
            'status' => $tender->status->value,
            'provider' => $tender->provider,
            'reference' => $tender->reference,
            'amount' => self::money($tender->amount()),
        ];
    }

    /** @return array<string, mixed> */
    public static function consent(ConsentData $consent): array
    {
        return [
            'type' => $consent->type,
            'document_version' => $consent->documentVersion,
            'document_url' => $consent->documentUrl,
            'agreed' => $consent->agreed,
            'agreed_at' => $consent->agreedAt,
        ];
    }

    /** @return array{minor: int, currency: string, exponent: int, decimal: string} */
    public static function money(Money $money): array
    {
        return [
            'minor' => $money->minor,
            'currency' => $money->currency,
            'exponent' => $money->exponent,
            'decimal' => $money->decimal(),
        ];
    }
}
