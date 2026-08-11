<?php

use Liberu\Ecommerce\PaymentOperations\Livewire\Components\PayForOrder;
use Liberu\Ecommerce\PaymentOperations\Livewire\Components\PaymentReceipt;
use Liberu\Ecommerce\PaymentOperations\Livewire\Components\SavedInstruments;
use Liberu\Ecommerce\PaymentOperations\Models\Payment;
use Liberu\Ecommerce\PaymentOperations\Models\PaymentInstrument;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Livewire;

/*
 * A writable public property on a shopper-facing component is a client-
 * controlled input, and this is a page about money — where the worst possible
 * client-controlled input is an amount and the second worst is an instrument.
 *
 * These assert by reflection over every registered component rather than per
 * class, so a component added later is covered before anybody remembers to cover
 * it.
 */

/** @return list<class-string<Component>> */
function everyComponent(): array
{
    return [PayForOrder::class, PaymentReceipt::class, SavedInstruments::class];
}

/** Every PHP file this package ships in `src/`. */
function everySourceFile(): Generator
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            yield $file->getPathname();
        }
    }
}

it('locks every public property, with no exceptions list at all', function (string $component) {
    // The checkout package keeps a short list of properties a shopper may write:
    // their email, their address, a discount code. This package keeps none,
    // because there is nothing on a payment surface a shopper types that this
    // package is allowed to receive. An exceptions list here would be the place
    // the next property gets added to.
    $properties = new ReflectionClass($component)->getProperties(ReflectionProperty::IS_PUBLIC);

    expect($properties)->not->toBeEmpty();

    foreach ($properties as $property) {
        if ($property->isStatic()) {
            continue;
        }

        expect($property->getAttributes(Locked::class))
            ->not->toBeEmpty("{$component}::\${$property->getName()} is writable from the browser.");
    }
})->with(everyComponent());

it('holds no money on any component at all, so there is nothing to lock', function (string $component) {
    // The amount is read from the host's resolver on the request that charges. A
    // `#[Locked]` amount would still be one dehydration bug away from being a
    // number a shopper set.
    foreach (new ReflectionClass($component)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        expect($property->getName())->not->toMatch('/(minor|price|total|amount|currency)/i');
    }
})->with(everyComponent());

it('has no property an instrument could be put in', function (string $component) {
    // Not a naming convention. A property is hydrated from a browser payload, so
    // a property named for a card is a card arriving in a request body — and the
    // domain's schema has no column that could hold what arrived. This is the
    // other half of that promise.
    foreach (new ReflectionClass($component)->getProperties() as $property) {
        expect($property->getName())
            ->not->toMatch('/(card|pan|cvv|cvc|iban|sort_?code|routing|accountnumber|account_number)/i');
    }
})->with(everyComponent());

it('renders no field a browser could type an instrument into', function () {
    $user = asCustomer();

    priced(customerId: (int) $user->id);

    $paid = payFor()->call('pay');

    // Every component this package registers, in one string. There is not an
    // `<input>` among them, and that absence is the assertion: a payment surface
    // with no field cannot be the thing a card number is typed into.
    $html = $paid->html()
        .Livewire::test(PaymentReceipt::class, ['reference' => $paid->get('paymentReference')])->html()
        .Livewire::test(SavedInstruments::class)->html();

    expect($html)->not->toMatch('/<(?:input|select|textarea)\b/i');
});

it('refuses an amount the browser tried to name', function () {
    priced(4798);

    expect(property_exists(PayForOrder::class, 'amountMinor'))->toBeFalse();

    // Livewire refuses to hydrate a property the component does not declare, so
    // an attempted write is a failed request rather than a cheaper order.
    expect(fn () => payFor()->set('amountMinor', 1))->toThrow(Exception::class);

    payFor()->call('pay');

    expect(Payment::query()->firstOrFail()->amount_minor)->toBe(4798);
});

it('refuses an idempotency key the browser tried to choose', function () {
    // `#[Locked]` is enforced by Livewire on hydration. Asserted on the message
    // rather than the class: Livewire has moved that exception between namespaces
    // across majors, and what this is about is the refusal, not where the class
    // lives.
    expect(fn () => payFor(priced())->set('idempotencyKey', 'a-key-i-picked'))
        ->toThrow(Exception::class, 'Cannot update locked property: [idempotencyKey]');
});

it('refuses a reference the browser tried to swap', function () {
    priced(4798, 'ORD-1');
    priced(100, 'ORD-CHEAP');

    // The reference is what the amount is derived from, so a swapped reference is
    // a swapped amount. That is the whole reason it is locked.
    expect(fn () => payFor('ORD-1')->set('reference', 'ORD-CHEAP'))
        ->toThrow(Exception::class, 'Cannot update locked property: [reference]');
});

it('refuses a token that is an instrument rather than a stand-in for one', function () {
    $component = payFor(priced());

    foreach (['4242424242424242', '4242 4242 4242 4242', '4242-4242-4242-4242', 'GB29NWBK60161331926819'] as $instrument) {
        $component->call('pay', $instrument)
            ->assertSet('paid', false)
            ->assertSee(__('module-ecommerce-payment-operations::payments.pay.instrument_refused'));
    }

    // Refused before the gateway was called and before anything was written, so
    // what arrived is in no row, no log line and no provider request.
    expect(Payment::query()->count())->toBe(0)
        ->and(PaymentInstrument::query()->count())->toBe(0);
});

it('never renders a provider token', function () {
    $user = asCustomer();

    $instrument = PaymentInstrument::factory()->create([
        'customer_id' => (int) $user->id,
        'provider_token' => 'tok_a_credential_that_moves_money',
    ]);

    // The component maps each model to a plain array before the view sees it, so
    // this is a view that was never handed the token rather than a view with a
    // convention about not printing it.
    expect(Livewire::test(SavedInstruments::class)->html())
        ->toContain('4242')
        ->not->toContain($instrument->provider_token)
        ->not->toContain('provider_token');
});

it('names no payment provider anywhere in src', function () {
    $offenders = [];

    foreach (everySourceFile() as $file) {
        // Provider-neutral, the same way the domain is: the host binds the
        // gateway and owns the widget. A name here would be a package shipping an
        // opinion about somebody else's merchant account.
        if (preg_match('/\b(stripe|paypal|braintree|adyen|klarna|square|mollie)\b/i', (string) file_get_contents($file)) === 1) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});

it('never reaches for an application class', function () {
    foreach (everySourceFile() as $file) {
        expect((string) file_get_contents($file))
            ->not->toMatch('/(?:use|new|extends|implements)\s+App\\\\/');
    }
});

it('imports no sibling commerce module but the one it presents', function () {
    foreach (everySourceFile() as $file) {
        preg_match_all('/^use (Liberu\\\\Ecommerce\\\\[A-Za-z]+)\\\\/m', (string) file_get_contents($file), $imports);

        foreach (array_unique($imports[1]) as $import) {
            expect($import)->toBe('Liberu\Ecommerce\PaymentOperations');
        }
    }
});

it('does no float arithmetic on anybody\'s money', function () {
    foreach (everySourceFile() as $file) {
        // Comments stripped first: the docblocks here quote the `float $amount`
        // of the interface this fleet replaced, and that quotation is the point.
        $source = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token)) {
                $source .= $token;

                continue;
            }

            if (! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $source .= $token[1];
            }
        }

        expect($source)
            ->not->toMatch('/\b(float|double)\b/i')
            ->not->toContain('number_format');
    }
});
