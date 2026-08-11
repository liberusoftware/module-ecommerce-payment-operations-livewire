<?php

use Liberu\Ecommerce\PaymentOperations\Actions\CapturePayment;
use Liberu\Ecommerce\PaymentOperations\Actions\RefundPayment;
use Liberu\Ecommerce\PaymentOperations\Data\Money;
use Liberu\Ecommerce\PaymentOperations\Data\MovementInput;
use Liberu\Ecommerce\PaymentOperations\Enums\EntryKind;
use Liberu\Ecommerce\PaymentOperations\Livewire\Components\PaymentReceipt;
use Liberu\Ecommerce\PaymentOperations\Models\Payment;
use Liberu\Ecommerce\PaymentOperations\Models\PaymentEntry;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * A payment as the person who made it sees it: folded from the ledger, every
 * time, and shown to nobody else.
 */

/** A payment made by whoever is signed in, and its reference. */
function ownPayment(int $minor = 4798): string
{
    priced($minor, customerId: (int) auth()->id());

    return payFor()->call('pay')->get('paymentReference');
}

function receipt(string $reference): Testable
{
    return Livewire::test(PaymentReceipt::class, ['reference' => $reference]);
}

it('shows the payer their own payment', function () {
    asCustomer();

    receipt(ownPayment())
        ->assertSee(__('module-ecommerce-payment-operations::payments.status.authorized'))
        ->assertSee('GBP 47.98');
});

it('404s on somebody else\'s payment', function () {
    asCustomer();

    $theirs = ownPayment();

    // The second customer, asking for the first one's reference. Answered exactly
    // as an invented reference would be: telling a stranger that a reference
    // exists but is not theirs is telling them a reference exists.
    asCustomer();

    expect(fn () => receipt($theirs))->toThrow(NotFoundHttpException::class);
});

it('404s on a reference that names nothing', function () {
    asCustomer();

    expect(fn () => receipt('PAY-NOT-A-REAL-ONE'))->toThrow(NotFoundHttpException::class);
});

it('404s on a guest payment for everybody, including the guest', function () {
    // A payment with no `customer_id` has no identity to check a viewer against,
    // and a `PAY-…` reference is not a credential — it is short, it is printed on
    // things, and treating it as one would make every guest receipt readable by
    // whoever guessed a reference. A guest sees the outcome in the pay component
    // that made it and nowhere else.
    $guestPayment = payFor(priced())->call('pay')->get('paymentReference');

    expect(fn () => receipt($guestPayment))->toThrow(NotFoundHttpException::class);

    asCustomer();

    expect(fn () => receipt($guestPayment))->toThrow(NotFoundHttpException::class);
});

it('shows nobody anything when the viewer cannot be identified as a number', function () {
    asCustomer();

    $mine = ownPayment();

    // A host whose ids are ULIDs. `customer_id` is an integer column, so there is
    // no viewer this could match — and it fails closed and completely silently,
    // which is worth knowing about when every list is empty.
    config()->set('payment-operations-livewire.viewer', ViewerWithAUlid::class);

    expect(fn () => receipt($mine))->toThrow(NotFoundHttpException::class);
});

it('folds the current state rather than reading a status somebody wrote', function () {
    asCustomer();

    $reference = ownPayment();

    $component = receipt($reference)->assertSee(__('module-ecommerce-payment-operations::payments.status.authorized'));

    // A capture and a partial refund recorded by something else entirely — a
    // webhook, an operator, a settlement job. There is no cache to bust and no
    // status column to update: the next render sums the ledger again.
    app(CapturePayment::class)->handle(new MovementInput($reference, 'a-capture-key'));
    app(RefundPayment::class)->handle(new MovementInput($reference, 'a-refund-key', new Money(1_000, 'GBP')));

    $component->call('$refresh')
        ->assertSee(__('module-ecommerce-payment-operations::payments.status.partially_refunded'))
        ->assertSee('GBP 10.00');
});

it('shows the ledger as a history, including what arrived by webhook', function () {
    asCustomer();

    $reference = ownPayment();
    $payment = Payment::query()->where('reference', $reference)->firstOrFail();

    // A provider-origin row is a fact about this shopper's money that arrived
    // without their click. Hiding it would leave the history disagreeing with the
    // totals folded from it.
    PaymentEntry::factory()->of($payment)->kind(EntryKind::Captured, 4798)->fromProvider()->create();

    $html = receipt($reference)->html();

    expect($html)
        ->toContain(__('module-ecommerce-payment-operations::payments.kind.authorized'))
        ->toContain(__('module-ecommerce-payment-operations::payments.kind.captured'));
});

it('says what a status means rather than repeating the domain\'s word for it', function () {
    asCustomer();

    // "Authorized" reads to a customer as "you have been charged". They have not
    // been: the money is reserved.
    expect(receipt(ownPayment())->html())
        ->toContain('Reserved, not yet taken')
        ->not->toContain('>authorized<');
});

it('shows a brand and four digits, which is what a receipt needs', function () {
    asCustomer();

    priced(4798, customerId: (int) auth()->id());

    $reference = payFor()->call('pay', 'tok_from_the_widget')->get('paymentReference');

    expect(receipt($reference)->html())->toContain('4242');
});

it('shows no gateway name and no provider reference', function () {
    asCustomer();

    $reference = ownPayment();
    $payment = Payment::query()->where('reference', $reference)->firstOrFail();

    // Neither means anything to a shopper, and the first is a support handle
    // rather than a receipt line.
    expect(receipt($reference)->html())
        ->not->toContain((string) $payment->provider_reference)
        ->not->toContain('card');
});
