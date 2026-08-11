<?php

return [

    /*
    |--------------------------------------------------------------------------
    | What is owed, and who owes it
    |--------------------------------------------------------------------------
    |
    | **The amount is never a client input.** The pay component is mounted with
    | an opaque reference — an order number, a checkout reference, whatever the
    | host issues — and that reference is turned into an amount *here*, on the
    | server, by a class the deployment names.
    |
    | Name a class the container can build and that is invokable as:
    |
    |     __invoke(string $reference): ?\Liberu\Ecommerce\PaymentOperations\Livewire\Data\Payable
    |
    | returning null for a reference that means nothing, which the component
    | answers with a 404. `Payable` carries the order id, the amount as a
    | `Money`, the gateway key, and the optional customer, team and checkout
    | reference the domain records alongside the payment.
    |
    | There is no default and there can be no default: a package that guessed
    | what somebody owed would be the bug this whole arrangement prevents. Left
    | unset, the pay component renders nothing it could take money with.
    |
    */

    'payable' => env('PAYMENT_OPERATIONS_LIVEWIRE_PAYABLE'),

    /*
    |--------------------------------------------------------------------------
    | Authorize only, or authorize and capture
    |--------------------------------------------------------------------------
    |
    | Off by default, which means the shopper's click **reserves** money and
    | something else takes it — a fulfilment job, an operator in the Filament
    | panel, a settlement run. That is the right default for anything shipped
    | in a box, because a merchant who captures before dispatch has taken money
    | for a parcel that may not go.
    |
    | On, the same click captures the whole authorization immediately, under an
    | entry key derived from the same idempotency key. That is the right
    | setting for something delivered at once — a download, a booking, a
    | top-up.
    |
    | The decision is the merchant's and there is no third option here. A
    | partial capture is an operator's judgement about a partly-shipped order,
    | and no shopper-facing button should be able to make one.
    |
    */

    'capture' => (bool) env('PAYMENT_OPERATIONS_LIVEWIRE_CAPTURE', false),

    /*
    |--------------------------------------------------------------------------
    | Who is looking
    |--------------------------------------------------------------------------
    |
    | The receipt and the saved-instrument list are scoped to one person, and
    | that person is identified by the id the domain stored in `customer_id`.
    |
    | Left unset this is `auth()->id()`, which is what a Liberu application
    | wants. Name a class invokable as `__invoke(): int|string|null` to say
    | otherwise — a host whose customers are not its users, say.
    |
    | A non-numeric id resolves to **nobody**, and nobody sees nothing. That
    | fails closed and it is completely silent, which is the direction to fail
    | in and worth knowing about when a ULID-keyed host sees empty lists.
    |
    */

    'viewer' => env('PAYMENT_OPERATIONS_LIVEWIRE_VIEWER'),

    /*
    |--------------------------------------------------------------------------
    | The gateway's own widget
    |--------------------------------------------------------------------------
    |
    | **No instrument ever reaches this package.** There is no card field here,
    | no property that could hold one, and no provider name anywhere in `src/`.
    | What tokenises an instrument is the provider's own client-side widget,
    | which the host wraps in a Livewire component and names here.
    |
    | The slot component is rendered inside the pay control and receives
    | `reference` and `amountMinor`. When it has a token it calls
    | `$parent.pay('tok_…')`, and this package passes that token straight to
    | the gateway without storing it.
    |
    | A token that looks like an instrument — a run of twelve to nineteen
    | digits, or an IBAN — is **refused before the gateway is called**, because
    | the one place a misconfigured widget could post a card number is here.
    |
    | Left unset the pay control still works for a gateway that needs no token
    | at all: a stored-credential charge, a redirect flow the host completes
    | server-side.
    |
    */

    'slots' => [
        'instrument' => env('PAYMENT_OPERATIONS_LIVEWIRE_INSTRUMENT_SLOT'),
    ],

];
