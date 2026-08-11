<?php

namespace Liberu\Ecommerce\PaymentOperations\Livewire\Tests\Fixtures;

use Liberu\Ecommerce\PaymentOperations\Livewire\Data\Payable;

/**
 * The host's pricing seam, standing in for whatever a real deployment names in
 * `payment-operations-livewire.payable`.
 *
 * Deliberately a lookup a test can change **between** a mount and a click, so
 * "the amount comes from the server on the request that charges" is something
 * asserted rather than described.
 */
final class PayableStub
{
    /** @var array<string, Payable> */
    public static array $prices = [];

    public function __invoke(string $reference): ?Payable
    {
        return self::$prices[$reference] ?? null;
    }
}
