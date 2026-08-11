<?php

use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\PaymentOperations\Actions\AuthorizePayment;
use Liberu\Ecommerce\PaymentOperations\Data\AuthorizationInput;
use Liberu\Ecommerce\PaymentOperations\Data\Money;
use Liberu\Ecommerce\PaymentOperations\Enums\EntryKind;
use Liberu\Ecommerce\PaymentOperations\Events\PaymentAuthorized;
use Liberu\Ecommerce\PaymentOperations\Models\Payment;
use Liberu\Ecommerce\PaymentOperations\Models\PaymentEntry;

/*
 * The double-submit problem, which is the whole job of this package — and here
 * it charges a card.
 *
 * The domain solved it with a caller's key and two unique indexes. What these
 * assert is that the component *uses* it: above all that the key is minted when
 * the payment step is entered rather than when the button is pressed, because a
 * key per click is two keys for one intent, and two keys against one card is two
 * reservations of somebody's money.
 */

it('has its key before the shopper has touched anything', function () {
    $component = payFor(priced());

    // Minted at mount. Not at click, not on first render of the button, not
    // lazily on the way into `pay()`.
    expect($component->get('idempotencyKey'))->not->toBe('');
});

it('holds one key across every press of the button', function () {
    $component = payFor(priced());

    $minted = $component->get('idempotencyKey');

    $component->call('pay')->call('pay')->call('pay');

    expect($component->get('idempotencyKey'))->toBe($minted);
});

it('authorizes under the key it minted, not one made at click time', function () {
    $component = payFor(priced());

    $minted = $component->get('idempotencyKey');

    $component->call('pay');

    expect(Payment::query()->pluck('payment_key')->all())->toBe([$minted]);
});

it('answers an impatient second press with the first press\'s payment and no second charge', function () {
    Event::fake([PaymentAuthorized::class]);

    $component = payFor(priced());

    $component->call('pay')
        ->assertSet('paid', true)
        ->assertSet('replayed', false)
        ->assertSet('problem', '');

    $reference = $component->get('paymentReference');

    $component->call('pay')
        ->assertSet('paid', true)
        ->assertSet('replayed', true)
        ->assertSet('paymentReference', $reference)
        ->assertSee(__('module-ecommerce-payment-operations::payments.pay.replayed'));

    // One payment, one ledger row, one event. The second press was answered by
    // the record of the first.
    expect(Payment::query()->count())->toBe(1)
        ->and(PaymentEntry::query()->count())->toBe(1);

    Event::assertDispatchedTimes(PaymentAuthorized::class, 1);
});

it('says still processing while the first attempt is still working', function () {
    Event::fake([PaymentAuthorized::class]);

    $component = payFor(priced());

    // A real in-flight claim, in the schema, not a stubbed exception. The first
    // attempt got as far as claiming `{key}:authorize` on the entries table and
    // has not committed a payment under the key — which is precisely the shape a
    // crash between the provider approving and the commit leaves behind, and the
    // one `AuthorizePayment` answers with the transient class.
    PaymentEntry::factory()->create([
        'entry_key' => $component->get('idempotencyKey').':authorize',
        'order_id' => 5_550_001,
    ]);

    $component->call('pay')
        ->assertSet('stillProcessing', true)
        ->assertSet('paid', false)
        // Never an error…
        ->assertSet('problem', '')
        ->assertSee(__('module-ecommerce-payment-operations::payments.pay.in_flight'))
        // …and never an empty success.
        ->assertDontSee(__('module-ecommerce-payment-operations::payments.pay.confirmed'));

    // And above all, never a second charge.
    expect(Payment::query()->where('order_id', 918_273)->count())->toBe(0);

    Event::assertNotDispatched(PaymentAuthorized::class);
});

it('offers a way to ask again while the first attempt is in flight', function () {
    $component = payFor(priced());

    PaymentEntry::factory()->create([
        'entry_key' => $component->get('idempotencyKey').':authorize',
        'order_id' => 5_550_002,
    ]);

    expect($component->call('pay')->html())->toContain('data-payment-retry');
});

it('refuses rather than starting a second payment when the key was spent on a different amount', function () {
    $reference = priced(4798);
    $component = payFor($reference);

    // The same key, already spent, on facts that are not these. Real: made
    // through the domain rather than seeded, so the stored hash is the one
    // `AuthorizePayment` would compare against.
    app(AuthorizePayment::class)->handle(new AuthorizationInput(
        orderId: 555_444,
        paymentKey: $component->get('idempotencyKey'),
        gateway: 'card',
        amount: new Money(1_000, 'GBP'),
    ));

    $component->call('pay')
        ->assertSet('paid', false)
        ->assertSet('stillProcessing', false)
        ->assertSee(__('module-ecommerce-payment-operations::payments.pay.conflict'));

    // **No fresh key.** A conflict means a payment already exists under this
    // one, so minting another would authorize a second one for the new total
    // with nobody having decided to. The checkout package drops its key here
    // because a conflict there means nothing was committed; this is the opposite
    // situation and takes the opposite answer.
    expect(Payment::query()->where('order_id', 918_273)->count())->toBe(0);
});

it('does not burn the key when a refusal happens before the gateway', function () {
    $component = payFor(priced());

    $minted = $component->get('idempotencyKey');

    // A payment form wired to post the card field instead of the token it was
    // given. Refused here, before anything is sent and before anything is
    // written — so the key is untouched.
    $component->call('pay', '4242 4242 4242 4242')
        ->assertSet('paid', false)
        ->assertSet('idempotencyKey', $minted)
        ->assertSee(__('module-ecommerce-payment-operations::payments.pay.instrument_refused'));

    expect(Payment::query()->count())->toBe(0);

    // Corrected and pressed again, with the same key — which is exactly what a
    // shopper does.
    $component->call('pay', 'tok_live_abc')
        ->assertSet('paid', true)
        ->assertSet('idempotencyKey', $minted);

    expect(Payment::query()->pluck('payment_key')->all())->toBe([$minted]);
});

it('mints a fresh key after a decline, because the old one can only replay it', function () {
    $component = payFor(priced());

    $declined = $component->get('idempotencyKey');

    $component->call('pay', 'tok_declined')
        ->assertSet('paid', false)
        ->assertSet('declineCode', 'card_declined')
        ->assertSee(__('module-ecommerce-payment-operations::payments.pay.declined'));

    // A decline is a committed fact — a `failed` entry of zero — so the key is
    // spent on the record that nothing was taken. Replaying it for ever would
    // leave the shopper unable to try another card, and a new key is safe
    // precisely because the old one definitively moved no money.
    expect($component->get('idempotencyKey'))->not->toBe('')->not->toBe($declined);

    $component->call('pay', 'tok_good')->assertSet('paid', true);

    expect(PaymentEntry::query()->where('kind', EntryKind::Failed->value)->count())->toBe(1)
        ->and(PaymentEntry::query()->where('kind', EntryKind::Authorized->value)->count())->toBe(1);
});

it('will not pay again from a component that reloaded onto a paid order', function () {
    Event::fake([PaymentAuthorized::class]);

    $reference = priced();

    payFor($reference)->call('pay')->assertSet('paid', true);

    // A new tab, a reload, a restored page. It holds a key of its own, so
    // calling the domain would reserve the money a second time — and the ledger,
    // read at mount, is what stops it.
    $reloaded = payFor($reference);

    $reloaded->assertSet('alreadyPaid', true)
        ->assertDontSee('data-payment-pay')
        ->call('pay')
        ->assertSee(__('module-ecommerce-payment-operations::payments.pay.already'));

    expect(Payment::query()->count())->toBe(1);

    Event::assertDispatchedTimes(PaymentAuthorized::class, 1);
});

it('disables the button on submit as well, because the key is the guarantee and this is the courtesy', function () {
    expect(payFor(priced())->html())
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:target="pay"')
        ->toContain(__('module-ecommerce-payment-operations::payments.pay.paying'));
});

it('captures under a key derived from the same one, so a second press captures nothing twice', function () {
    config()->set('payment-operations-livewire.capture', true);

    $component = payFor(priced());

    $minted = $component->get('idempotencyKey');

    $component->call('pay')->call('pay');

    expect(PaymentEntry::query()->orderBy('id')->pluck('entry_key')->all())
        ->toBe([$minted.':authorize', $minted.':capture']);
});
