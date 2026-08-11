<?php

use Liberu\Ecommerce\PaymentOperations\Livewire\Components\PaymentReceipt;
use Liberu\Ecommerce\PaymentOperations\Livewire\Components\SavedInstruments;
use Liberu\Ecommerce\PaymentOperations\Models\PaymentEntry;
use Liberu\Ecommerce\PaymentOperations\Models\PaymentInstrument;
use Livewire\Livewire;

/*
 * Accessibility that is assertable from rendered output.
 *
 * These are the parts a theme can break by accident when it publishes a view — a
 * refusal nobody is told about, an outcome that changes in silence, a row of
 * identical buttons. They are behaviour, not decoration, and a published view
 * that drops them fails here rather than in production.
 */

it('gives every field it renders a label of its own', function () {
    // Vacuous today, deliberately: there is no field on any of these components,
    // because there is nothing on a payment surface a shopper types that this
    // package may receive. It starts meaning something the moment that changes.
    $html = payFor(priced())->html();

    expect($html)->not->toBeEmpty();

    expectEveryFieldToBeLabelled($html);
});

it('puts the loading state inside a live region rather than beside it', function () {
    expect(payFor(priced())->html())
        ->toMatch('/<p role="status" aria-live="polite">\s*<span wire:loading/');
});

it('interrupts with a refusal rather than leaving it in a polite queue', function () {
    // A refusal is not a status update. `role="alert"` is assertive and
    // `role="status"` is not, and a shopper who cannot see the page needs to know
    // now that their payment was refused.
    // Livewire wraps a conditional in its own block comments, so the two are
    // adjacent rather than touching.
    expect(payFor(priced())->call('pay', '4242424242424242')->html())
        ->toMatch('/<div role="alert" data-payment-alert>(?s).{0,160}?<p data-payment-problem>/');
});

it('says out loud that a payment was approved', function () {
    // A button that dims and a panel that swaps are both invisible to a screen
    // reader.
    payFor(priced())->call('pay')
        ->assertSee(__('module-ecommerce-payment-operations::payments.pay.confirmed'));
});

it('says out loud that the first attempt is still going, rather than showing an unchanged page', function () {
    $component = payFor(priced());

    PaymentEntry::factory()->create([
        'entry_key' => $component->get('idempotencyKey').':authorize',
        'order_id' => 4_440_001,
    ]);

    $component->call('pay')
        ->assertSee(__('module-ecommerce-payment-operations::payments.pay.in_flight'));
});

it('says out loud which card was removed', function () {
    $user = asCustomer();

    $instrument = PaymentInstrument::factory()->create(['customer_id' => (int) $user->id]);

    expect(Livewire::test(SavedInstruments::class)->call('detach', $instrument->reference)->html())
        ->toContain('data-payment-announcement');
});

it('reaches every control by keyboard, because every control is a button', function () {
    $user = asCustomer();

    PaymentInstrument::factory()->create(['customer_id' => (int) $user->id]);

    $html = payFor(priced())->html().Livewire::test(SavedInstruments::class)->html();

    // A `<div wire:click>` is unreachable by tab, unactivatable by space, and
    // announced as nothing. Every clickable thing this package renders is a real
    // button with a real type.
    expect($html)->not->toMatch('/<(?:div|span|a)\b[^>]*wire:click/i');

    preg_match_all('/<button\b[^>]*>/i', $html, $buttons);

    expect($buttons[0])->not->toBeEmpty();

    foreach ($buttons[0] as $button) {
        expect($button)->toContain('type="button"');
    }
});

it('keys every repeated row so focus survives a re-render', function () {
    $user = asCustomer();

    PaymentInstrument::factory()->create(['customer_id' => (int) $user->id]);

    expect(Livewire::test(SavedInstruments::class)->html())->toContain('wire:key="payment-instrument-');
});

it('starts each component at h2, so a host page keeps its own h1', function () {
    $user = asCustomer();

    priced(customerId: (int) $user->id);

    $reference = payFor()->call('pay')->get('paymentReference');

    // These are components a host composes into its own page. A component that
    // claimed the `<h1>` would give a page two outlines or none.
    foreach ([payFor(priced(4798, 'ORD-2', 555_001))->html(), Livewire::test(PaymentReceipt::class, ['reference' => $reference])->html(), Livewire::test(SavedInstruments::class)->html()] as $html) {
        expect($html)->toContain('<h2>')->not->toContain('<h1>');
    }
});

it('marks up the receipt as pairs and the history as an ordered list', function () {
    asCustomer();

    priced(customerId: (int) auth()->id());

    $reference = payFor()->call('pay')->get('paymentReference');

    // A `<dl>` is read as pairs and an `<ol>` announces its length and position.
    // A grid of `<div>`s is read as one run-on sentence.
    expect(Livewire::test(PaymentReceipt::class, ['reference' => $reference])->html())
        ->toContain('<dl data-payment-summary>')
        ->toContain('<ol data-payment-history>')
        ->toContain('<time datetime=');
});
