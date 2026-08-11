<?php

namespace Liberu\Ecommerce\PaymentOperations\Livewire\Data;

use Liberu\Ecommerce\PaymentOperations\Data\Money;

/**
 * **What a reference is worth, decided by the host, on the server.**
 *
 * The pay component is mounted with an opaque reference and nothing else. It
 * has no amount property, no currency property and no order id property,
 * because a browser that can name any of those can name a penny. What it does
 * instead is hand the reference to the class named in
 * `payment-operations-livewire.payable` and use whatever comes back.
 *
 * That resolver is the host's, and it must be, for the same reason the discount
 * resolver in the checkout package is: what somebody owes is a fact about an
 * order, and this package does not own orders. It owns the click.
 *
 * ### Why this is a type and not an array
 *
 * An array would have made the resolver a paragraph of documentation and the
 * component a pile of `is_int()` checks over somebody else's return value. The
 * amount is a `Money` — integer minor units and a currency, the domain's own
 * value type — so a host that tries to return a float cannot, and a host that
 * forgets the currency gets a `TypeError` at the seam rather than a wrong
 * number at the gateway.
 *
 * ### Why the resolver does not build the domain's `AuthorizationInput`
 *
 * That was the shorter version and it is the wrong one. `AuthorizationInput`
 * carries the idempotency key, so a resolver that built it would either be
 * handed the component's key — and could quietly ignore it, which is the
 * duplicate charge this package exists to prevent — or mint one of its own,
 * which is the same bug with a longer path. The key belongs to the component
 * and never leaves it.
 */
final readonly class Payable
{
    public function __construct(
        /** The order this pays for. A plain id: no module reaches into another's tables. */
        public int $orderId,
        /** What is owed, from the server, in integer minor units. */
        public Money $amount,
        /** Which configured gateway to ask. The key in `payment-operations.gateways`. */
        public string $gateway,
        /** Who owes it, if the host knows. Null for a guest, who then gets no durable receipt. */
        public ?int $customerId = null,
        public ?int $teamId = null,
        /** The checkout this came from, recorded so the two can be reconciled later. */
        public ?string $checkoutReference = null,
    ) {}
}
