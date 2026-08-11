<?php

use Liberu\Ecommerce\PaymentOperations\Data\Money;
use Liberu\Ecommerce\PaymentOperations\Livewire\Components\PayForOrder;
use Liberu\Ecommerce\PaymentOperations\Livewire\Data\Payable;
use Liberu\Ecommerce\PaymentOperations\Livewire\Tests\Fixtures\PayableStub;
use Liberu\Ecommerce\PaymentOperations\Testing\FakeGateway;
use Liberu\PackageTestbench\PackageTestCase;
use Liberu\PackageTestbench\TestUser;
use Liberu\PackageTestbench\UsesTestUser;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * `UsesTestUser` for the `users` table, because two of the three components here
 * are scoped to a person and the default way of finding them is `auth()->id()`.
 *
 * The pay component is not: a guest pays. Its tests never sign anybody in, and
 * that is the composition a storefront is actually in.
 */
uses(PackageTestCase::class, UsesTestUser::class)->in(__DIR__);

beforeEach(function (): void {
    PayableStub::$prices = [];

    config()->set('payment-operations-livewire.payable', PayableStub::class);
    config()->set('payment-operations-livewire.capture', false);
    config()->set('payment-operations-livewire.slots.instrument', null);
    config()->set('payment-operations-livewire.viewer', null);

    // A real, complete gateway with a real HMAC signature check, shipped by the
    // domain package for exactly this. The suite tests against the contract
    // rather than against a mock of it, and no provider is named anywhere.
    config()->set('payment-operations.gateways.card', [
        'class' => FakeGateway::class,
        'signing_secret' => 'a-secret-that-is-not-in-a-column',
    ]);
});

/**
 * A reference the host prices at something, and the amount it is worth.
 *
 * `4798` deliberately: two of a 19.99 thing plus 20% tax, the same figure the
 * checkout package uses, so a decimal rendered by float arithmetic would look
 * wrong rather than round cleanly.
 */
function priced(
    int $minor = 4798,
    string $reference = 'ORD-1',
    int $orderId = 918_273,
    ?int $customerId = null,
    string $currency = 'GBP',
    int $exponent = 2,
): string {
    PayableStub::$prices[$reference] = new Payable(
        orderId: $orderId,
        amount: new Money($minor, $currency, $exponent),
        gateway: 'card',
        customerId: $customerId,
        teamId: 9_000_007,
        checkoutReference: 'CHK-'.$reference,
    );

    return $reference;
}

/** The pay control, mounted on a reference. */
function payFor(string $reference = 'ORD-1'): Testable
{
    return Livewire::test(PayForOrder::class, ['reference' => $reference]);
}

/** Somebody signed in, whose id is what `customer_id` is compared against. */
function asCustomer(): TestUser
{
    $user = TestUser::factory()->create();

    test()->actingAs($user);

    return $user;
}

/**
 * Every field a component renders has a real label pointing at it.
 *
 * A placeholder is not a label: it disappears on the first keystroke and screen
 * readers are not obliged to read it. This walks the rendered markup rather than
 * trusting a per-view assertion, so a field added later without a label fails
 * here rather than never.
 *
 * These components render no fields at all today — there is nothing on a payment
 * surface a shopper types that this package is allowed to receive — so this
 * passes vacuously and starts meaning something the moment that changes.
 */
function expectEveryFieldToBeLabelled(string $html): void
{
    preg_match_all('/<(?:input|select|textarea)\b[^>]*>/i', $html, $fields);

    foreach ($fields[0] as $field) {
        expect($field)->toMatch('/\sid="[^"]+"/');

        preg_match('/\sid="([^"]+)"/', $field, $id);

        expect($html)->toContain('for="'.$id[1].'"');
    }
}
