<?php

use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\PaymentOperations\Livewire\Components\PayForOrder;
use Liberu\Ecommerce\PaymentOperations\Livewire\Components\PaymentReceipt;
use Liberu\Ecommerce\PaymentOperations\Livewire\Components\SavedInstruments;
use Liberu\Ecommerce\PaymentOperations\Livewire\PaymentOperationsLivewireServiceProvider as Provider;
use Livewire\Livewire;

/*
 * The package's public surface: three aliases, and the registration that makes
 * a namespaced name resolvable at all.
 */

it('publishes exactly three components, and their names are the interface', function () {
    // Explicit rather than discovered. A directory scan resolves whatever happens
    // to be on disk, so moving a class or adding one would silently change a
    // public interface; this list *is* the interface, and changing it is a diff
    // somebody reviews.
    expect(app(Provider::class)->aliases())->toBe([
        'module-ecommerce-payment-operations::pay' => PayForOrder::class,
        'module-ecommerce-payment-operations::receipt' => PaymentReceipt::class,
        'module-ecommerce-payment-operations::instruments' => SavedInstruments::class,
    ]);
});

it('mounts the pay control by its namespaced alias', function () {
    // The half that costs an afternoon when it is missing. Livewire 4's
    // `Finder::resolveClassComponentClassName()` returns null for a
    // `namespace::name` *before* it consults the explicit registry, so
    // `Livewire::component()` alone never answers one. `resolveMissingComponent()`
    // is what does, and this is the proof.
    priced();

    expect(Livewire::test('module-ecommerce-payment-operations::pay', ['reference' => 'ORD-1'])->html())
        ->toContain('GBP 47.98');
});

it('mounts the receipt by its namespaced alias', function () {
    asCustomer();

    priced(customerId: (int) auth()->id());

    $reference = payFor()->call('pay')->get('paymentReference');

    expect(Livewire::test('module-ecommerce-payment-operations::receipt', ['reference' => $reference])->html())
        ->toContain($reference);
});

it('mounts the saved instruments by its namespaced alias', function () {
    asCustomer();

    expect(Livewire::test('module-ecommerce-payment-operations::instruments')->html())
        ->toContain(__('module-ecommerce-payment-operations::payments.instrument.heading'));
});

it('registers no route of its own', function () {
    // Routes belong to the application. A package that registered
    // `/payments/{reference}` would have decided a host's URL structure and
    // collided with whatever was already there.
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains((string) $route->uri(), 'payment'));

    expect($routes)->toBeEmpty();
});

it('publishes its views, translations and config under one tag prefix', function () {
    // A deployment overriding the wording rarely wants its own markup as well —
    // and on a payment page the wording is the part a compliance team edits.
    expect(ServiceProvider::publishableGroups())
        ->toContain('module-ecommerce-payment-operations-views')
        ->toContain('module-ecommerce-payment-operations-translations')
        ->toContain('module-ecommerce-payment-operations-config');
});
