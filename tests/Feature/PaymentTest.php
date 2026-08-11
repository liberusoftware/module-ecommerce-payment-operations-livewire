<?php

use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\PaymentOperations\Enums\EntryKind;
use Liberu\Ecommerce\PaymentOperations\Enums\PaymentStatus;
use Liberu\Ecommerce\PaymentOperations\Events\PaymentCaptured;
use Liberu\Ecommerce\PaymentOperations\Livewire\Tests\Fixtures\InstrumentWidget;
use Liberu\Ecommerce\PaymentOperations\Models\Payment;
use Liberu\Ecommerce\PaymentOperations\Models\PaymentEntry;
use Liberu\Ecommerce\PaymentOperations\Models\PaymentInstrument;
use Liberu\Ecommerce\PaymentOperations\Queries\PaymentQuery;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * What the button does, and where each number in it came from.
 */

it('reserves money without taking it, which is the default', function () {
    payFor(priced(4798))->call('pay')->assertSet('paid', true);

    $payment = app(PaymentQuery::class)->forOrder(918_273)->firstOrFail();

    // Authorized, not captured. A merchant who captures before dispatch has taken
    // money for a parcel that may not go, so nothing shipped in a box should do
    // it from a shopper's click.
    expect($payment->state->status())->toBe(PaymentStatus::Authorized)
        ->and($payment->state->authorizedMinor)->toBe(4798)
        ->and($payment->state->capturedMinor)->toBe(0);
});

it('takes it in the same click when the deployment says so', function () {
    Event::fake([PaymentCaptured::class]);

    config()->set('payment-operations-livewire.capture', true);

    payFor(priced(4798))->call('pay')->assertSet('paid', true);

    $payment = app(PaymentQuery::class)->forOrder(918_273)->firstOrFail();

    expect($payment->state->status())->toBe(PaymentStatus::Captured)
        ->and($payment->state->capturedMinor)->toBe(4798);

    Event::assertDispatchedTimes(PaymentCaptured::class, 1);
});

it('captures what the domain folded rather than a number this component worked out', function () {
    config()->set('payment-operations-livewire.capture', true);

    payFor(priced(4798))->call('pay');

    // No amount is passed to `CapturePayment`, so the amount captured is the one
    // the domain folded under its own lock. Computing it here would mean
    // computing it from a read that is already stale.
    expect(PaymentEntry::query()->where('kind', EntryKind::Captured->value)->firstOrFail()->amount_minor)
        ->toBe(4798);
});

it('takes the amount from the server on the request that charges, not from the one that rendered', function () {
    $reference = priced(4798);

    $component = payFor($reference);

    // The host re-prices between the page rendering and the button being pressed.
    // Nothing the browser holds decides this: the resolver is asked again.
    priced(5_500, $reference);

    $component->call('pay')->assertSet('paid', true);

    expect(Payment::query()->firstOrFail()->amount_minor)->toBe(5_500);
});

it('renders an amount by string arithmetic, so a penny is a penny', function () {
    // 4798 is two of a 19.99 thing plus 20% tax — a total whose decimal a float
    // would round rather than represent. `(int) (47.98 * 100)` is 4797.
    expect(payFor(priced(4798))->html())->toContain('GBP 47.98');
});

it('handles a currency with no minor unit at all', function () {
    // Exponent 0. A formatter that assumed two places would show 500 yen as 5.00.
    expect(payFor(priced(500, 'ORD-JPY', currency: 'JPY', exponent: 0))->html())
        ->toContain('JPY 500');
});

it('records a decline as a fact rather than raising it as an outage', function () {
    payFor(priced())->call('pay', 'tok_declined')
        ->assertSet('paid', false)
        ->assertSet('declineCode', 'card_declined');

    // The domain writes a `failed` entry of zero and the payment stays. A merchant
    // asking "how many did we lose on this card type last week" has something to
    // count, which throwing would have thrown away.
    $payment = app(PaymentQuery::class)->forOrder(918_273)->firstOrFail();

    expect($payment->state->status())->toBe(PaymentStatus::Failed)
        ->and($payment->state->failedCount)->toBe(1)
        ->and($payment->state->authorizedMinor)->toBe(0);
});

it('shows the provider\'s short code and never its prose', function () {
    $html = payFor(priced())->call('pay', 'tok_declined')->html();

    // Free text from a provider next to a shopper's page is where somebody's
    // email address ends up. The domain stores a code for the same reason.
    expect($html)->toContain(__('module-ecommerce-payment-operations::payments.pay.decline_code', ['code' => 'card_declined']));
});

it('stores the descriptor the gateway returned and nothing the shopper sent', function () {
    payFor(priced(4798, customerId: 5_000_001))->call('pay', 'tok_from_the_widget');

    $instrument = PaymentInstrument::query()->firstOrFail();

    // A brand, four digits and an expiry — a receipt line, not an instrument. The
    // token is the provider's handle, written by the domain, and it never came
    // from a form.
    expect($instrument->brand)->toBe('test-brand')
        ->and($instrument->last_four)->toBe('4242')
        ->and($instrument->customer_id)->toBe(5_000_001);
});

it('404s on a reference the host prices at nothing', function () {
    // Answered exactly as a reference that was never issued. The difference
    // between the two is information about somebody else's order.
    expect(fn () => payFor('ORD-NEVER-ISSUED'))
        ->toThrow(NotFoundHttpException::class);
});

it('says a shop cannot take payments rather than showing a button that cannot work', function () {
    config()->set('payment-operations-livewire.payable', null);

    payFor('ORD-1')
        ->assertSet('unconfigured', true)
        ->assertSee(__('module-ecommerce-payment-operations::payments.pay.unconfigured'))
        ->assertDontSee('data-payment-pay')
        ->call('pay');

    expect(Payment::query()->count())->toBe(0);
});

it('renders the host\'s tokenising widget where one is wired, and nothing where none is', function () {
    expect(payFor(priced())->html())->not->toContain('data-instrument-widget');

    Livewire::component('test-instrument-widget', InstrumentWidget::class);

    config()->set('payment-operations-livewire.slots.instrument', 'test-instrument-widget');

    // The slot gets the reference and what is owed, and gives back a token. It
    // never gets an instrument, because this package never has one.
    expect(payFor(priced())->html())->toContain('data-instrument-widget');
});

it('refuses to take a second payment for an order that already has one', function () {
    $reference = priced();

    payFor($reference)->call('pay');

    // Single-tender by design. The domain supports several payments against one
    // order — there is deliberately no unique key on `order_id` — but splitting a
    // total across two instruments is a decision a host's checkout makes, not one
    // made by pressing this button twice.
    payFor($reference)
        ->assertSet('alreadyPaid', true)
        ->call('pay')
        ->assertSee(__('module-ecommerce-payment-operations::payments.pay.already'));

    expect(Payment::query()->count())->toBe(1);
});

it('does not count a declined payment as this order having been paid', function () {
    $reference = priced();

    payFor($reference)->call('pay', 'tok_declined');

    // A `failed` entry is not money. A shopper whose card was refused must be
    // able to try again, and the ledger read at mount is a fold, not a row count.
    payFor($reference)->assertSet('alreadyPaid', false)->call('pay')->assertSet('paid', true);
});
