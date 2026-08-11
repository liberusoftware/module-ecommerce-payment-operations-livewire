<?php

use Liberu\Ecommerce\PaymentOperations\Livewire\Components\SavedInstruments;
use Liberu\Ecommerce\PaymentOperations\Livewire\Tests\Fixtures\ViewerWithAUlid;
use Liberu\Ecommerce\PaymentOperations\Models\PaymentInstrument;
use Livewire\Livewire;

/*
 * Saved payment methods: list, remove, and nowhere to add one.
 */

function instruments(): Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(SavedInstruments::class);
}

it('lists what this customer can still pay with', function () {
    $user = asCustomer();

    PaymentInstrument::factory()->create(['customer_id' => (int) $user->id, 'last_four' => '4242']);
    PaymentInstrument::factory()->create(['customer_id' => (int) $user->id, 'last_four' => '1881', 'brand' => 'other-brand']);

    expect(instruments()->html())->toContain('4242')->toContain('1881');
});

it('shows nobody else\'s cards', function () {
    $mine = asCustomer();

    PaymentInstrument::factory()->create(['customer_id' => (int) $mine->id, 'last_four' => '4242']);
    PaymentInstrument::factory()->create(['customer_id' => 9_999_999, 'last_four' => '1881']);

    expect(instruments()->html())->toContain('4242')->not->toContain('1881');
});

it('shows a signed-out visitor nothing at all', function () {
    PaymentInstrument::factory()->create(['last_four' => '4242']);

    expect(instruments()->html())
        ->not->toContain('4242')
        ->toContain(__('module-ecommerce-payment-operations::payments.instrument.empty'));
});

it('shows a host whose ids are not numbers nothing, silently', function () {
    asCustomer();

    PaymentInstrument::factory()->create(['customer_id' => 1, 'last_four' => '4242']);

    config()->set('payment-operations-livewire.viewer', ViewerWithAUlid::class);

    // Not `(int) $ulid`, which is `0` — and `0` is somebody's row on a database
    // that starts its sequences there.
    expect(instruments()->html())->not->toContain('4242');
});

it('removes one, and stops listing it', function () {
    $user = asCustomer();

    $instrument = PaymentInstrument::factory()->create(['customer_id' => (int) $user->id, 'last_four' => '4242']);

    instruments()
        ->call('detach', $instrument->reference)
        ->assertSee(__('module-ecommerce-payment-operations::payments.instrument.detached', [
            'label' => 'test-brand ending 4242',
        ]))
        // Gone from the list on the same render. The announcement still names it,
        // which is the point of an announcement.
        ->assertSee(__('module-ecommerce-payment-operations::payments.instrument.empty'));

    // Detached, not deleted. A payment made last month points at this row, and
    // deleting it would leave a ledger entry whose brand and last four came from
    // nowhere.
    expect($instrument->fresh()?->detached_at)->not->toBeNull()
        ->and(PaymentInstrument::query()->count())->toBe(1);
});

it('will not remove somebody else\'s, and says the same thing as for one that does not exist', function () {
    asCustomer();

    $theirs = PaymentInstrument::factory()->create(['customer_id' => 9_999_999]);

    // Scoped in the query rather than checked after it, so the two cases cannot
    // be told apart — and the difference between them is information.
    instruments()->call('detach', $theirs->reference)
        ->assertSee(__('module-ecommerce-payment-operations::payments.instrument.unknown'));

    instruments()->call('detach', 'INS-INVENTED')
        ->assertSee(__('module-ecommerce-payment-operations::payments.instrument.unknown'));

    expect($theirs->fresh()?->detached_at)->toBeNull();
});

it('will not remove one twice', function () {
    $user = asCustomer();

    $instrument = PaymentInstrument::factory()->detached()->create(['customer_id' => (int) $user->id]);

    instruments()->call('detach', $instrument->reference)
        ->assertSee(__('module-ecommerce-payment-operations::payments.instrument.unknown'));
});

it('names each remove button after the card it removes', function () {
    $user = asCustomer();

    PaymentInstrument::factory()->create(['customer_id' => (int) $user->id, 'last_four' => '4242']);
    PaymentInstrument::factory()->create(['customer_id' => (int) $user->id, 'last_four' => '1881']);

    // Four buttons all called "Remove" are one button to somebody tabbing
    // through.
    expect(instruments()->html())
        ->toContain(__('module-ecommerce-payment-operations::payments.instrument.remove', ['label' => 'test-brand ending 4242']))
        ->toContain(__('module-ecommerce-payment-operations::payments.instrument.remove', ['label' => 'test-brand ending 1881']));
});

it('offers no way to add one, and says why', function () {
    asCustomer();

    $html = instruments()->html();

    // Adding an instrument means holding an instrument for as long as it takes to
    // send it somewhere. The way to add a card is to pay with it.
    expect($html)
        ->not->toMatch('/<(?:input|select|textarea|form)\b/i')
        ->toContain(__('module-ecommerce-payment-operations::payments.instrument.how_to_add'));
});
