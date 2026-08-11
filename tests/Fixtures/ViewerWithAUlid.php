<?php

namespace Liberu\Ecommerce\PaymentOperations\Livewire\Tests\Fixtures;

/**
 * A host whose customers are keyed by something that is not a whole number.
 *
 * `ecommerce_payment_payments.customer_id` is an integer column, so there is no
 * viewer such a host can be matched against. What matters is *how* that fails:
 * closed and silently, rather than by casting a ULID to `0` and matching
 * whichever row a database that starts its sequences there happens to have.
 */
final class ViewerWithAUlid
{
    public function __invoke(): string
    {
        return '01JZ0X8Q2M4V6Y8A0C2E4G6J8K';
    }
}
