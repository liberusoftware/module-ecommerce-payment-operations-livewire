# Adoption and upgrade guidance

## Requirements

- PHP 8.5
- Laravel 13
- Livewire 4
- `liberusoftware/ecommerce-payment-operations` ^0.1

## Installing

Neither this package nor the domain module it presents is published on Packagist,
so the composition adds the domain repository before requiring either:

```jsonc
"repositories": [
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-payment-operations" },
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-payment-operations-livewire" }
]
```

```bash
composer require liberusoftware/ecommerce-payment-operations-livewire
```

The domain module comes with it as a runtime requirement. Run its migrations
before anything mounts a component:

```bash
php artisan migrate
```

## Enabling

Installing is not enabling. This package ships no `extra.laravel.providers`, so
Composer discovery boots nothing. The module registry does:

```dotenv
MODULES_ENABLED=ecommerce-payment-operations,ecommerce-payment-operations-livewire
```

Both. A presentation module with its domain module disabled has nothing to
present.

## Pricing a reference

**This is the one piece of wiring without which nothing works, and there is no
default.** A package that guessed what somebody owed would be the bug the whole
arrangement prevents.

Write a class the container can build, invokable with a reference and returning a
`Payable` or null:

```php
namespace App\Payments;

use App\Models\Order;
use Liberu\Ecommerce\PaymentOperations\Data\Money;
use Liberu\Ecommerce\PaymentOperations\Livewire\Data\Payable;

final class PriceAnOrder
{
    public function __invoke(string $reference): ?Payable
    {
        $order = Order::query()->where('reference', $reference)->first();

        if ($order === null || $order->isPaid()) {
            return null;
        }

        return new Payable(
            orderId: $order->id,
            amount: new Money($order->outstanding_minor, $order->currency),
            gateway: 'card',
            customerId: $order->customer_id,
            teamId: $order->team_id,
            checkoutReference: $order->checkout_reference,
        );
    }
}
```

```dotenv
PAYMENT_OPERATIONS_LIVEWIRE_PAYABLE="App\Payments\PriceAnOrder"
```

Three things about it:

- **It is asked again on the request that charges**, not only at mount. A total
  that changed in between is the one the gateway is asked for.
- **Returning null is a 404**, and it is the same 404 a reference that was never
  issued gets. Return null for an order that is not this shopper's: **the
  resolver is where a host decides who may pay for what.** Nothing downstream
  re-checks it.
- **It never receives or returns an idempotency key.** That belongs to the
  component and never leaves it, so a resolver cannot mint one or ignore one.

`gateway` is a key in `payment-operations.gateways`, which is the domain's
configuration, not this package's.

## Wiring a gateway

The domain resolves an implementation of `Contracts\PaymentGateway` by class name
at call time. This package never learns what it is called.

```php
// config/payment-operations.php
'gateways' => [
    'card' => [
        'class' => \App\Payments\CardGateway::class,
        'signing_secret' => env('PAYMENT_CARD_SIGNING_SECRET'),
    ],
],
```

`Testing\FakeGateway` in the domain package is a complete, working implementation
with a real HMAC signature check. Point a host test at it, or diff your adapter's
behaviour against it.

If no gateway is configured the pay control says the shop cannot take payments
yet, rather than rendering a button that can only fail.

## Wiring the widget that tokenises

**The shopper's card never touches this package.** The provider's own widget —
its iframe, its JS — collects the instrument and returns a token. Wrap it in a
Livewire component and name it:

```dotenv
PAYMENT_OPERATIONS_LIVEWIRE_INSTRUMENT_SLOT=app::card-widget
```

The slot receives exactly two props and gives back exactly one thing:

```php
public function mount(string $reference, int $amountMinor): void { … }
```

```blade
<button type="button" wire:click="$parent.pay('{{ $token }}')">Pay</button>
```

What you must not do is post the card field itself. The pay control **refuses** a
token that is a run of twelve to nineteen digits or an IBAN, before the gateway is
called and before anything is written — so a widget wired that way fails loudly on
its first use rather than quietly forever.

Left unconfigured, the pay control still works for a gateway that needs no token:
a stored-credential charge, or a redirect flow the host completes server-side.

## Authorize, or authorize and capture

```dotenv
PAYMENT_OPERATIONS_LIVEWIRE_CAPTURE=false
```

Off by default. The click **reserves** money and something else takes it — a
fulfilment job, an operator in the Filament panel, a settlement run. That is right
for anything shipped in a box: a merchant who captures before dispatch has taken
money for a parcel that may not go.

On, the same click captures the whole authorization, under an entry key derived
from the same idempotency key. That is right for something delivered at once — a
download, a booking, a top-up.

There is no third option. A partial capture is an operator's judgement about a
partly-shipped order, and no shopper-facing button should be able to make one.

## Identifying a viewer

The receipt and the instrument list are scoped to `customer_id`. By default that
is compared against `auth()->id()`.

```dotenv
PAYMENT_OPERATIONS_LIVEWIRE_VIEWER="App\Payments\CurrentCustomer"
```

Invokable as `__invoke(): int|string|null`. **An id that is not a whole number
resolves to nobody, and nobody sees nothing** — `customer_id` is an integer column
in the domain. A ULID-keyed host will find every list empty and no error anywhere,
which is the safe direction and the first thing to check if that is what you see.

## Composing the components

```blade
{{-- A page you own, with your own <h1> --}}
<h1>Pay for order {{ $order->reference }}</h1>

<livewire:module-ecommerce-payment-operations::pay :reference="$order->reference" />
```

```blade
<livewire:module-ecommerce-payment-operations::receipt :reference="$payment->reference" />

<livewire:module-ecommerce-payment-operations::instruments />
```

The pay control dispatches one browser event on a successful, newly-recorded
payment:

```js
Livewire.on('module-ecommerce-payment-operations.paid', ({ reference }) => { … })
```

Identifier only, and not on a replay. Anything richer is the domain's
`PaymentAuthorized`, which a replay deliberately does not dispatch either.

## What the host must migrate away from

The host's `App\Models\PaymentMethod` is **not adopted**, and this package does
not read it. It is `$fillable = ['user_id', 'name', 'details', 'is_default']` with
no `$hidden` and no cast on `details`, and `PaymentMethodController` returns raw
`details` to the client.

There is no migration path for that column, deliberately: whatever is in it may
not be storable at all. The steps are

1. stop writing to it — new instruments come from `AuthorizePayment` writing the
   gateway's descriptor,
2. move any provider tokens in it into `ecommerce_payment_instruments`
   (`gateway`, `provider_token`, `brand`, `last_four`, `expiry_*`),
3. drop the column, and
4. remove `App\Interfaces\PaymentGatewayInterface` and
   `App\Services\PaymentGateways\*` in favour of a
   `Contracts\PaymentGateway` adapter.

`is_default` has no home here on purpose: a default payment method is a
*preference*, and a ledger module storing one would be acquiring a second domain.

## Overriding views and wording

```bash
php artisan vendor:publish --tag=module-ecommerce-payment-operations-views
php artisan vendor:publish --tag=module-ecommerce-payment-operations-translations
php artisan vendor:publish --tag=module-ecommerce-payment-operations-config
```

Wording publishes separately from markup, because a merchant's compliance team
edits the first and a theme edits the second.

If you publish the views, keep the live regions, the `wire:key`s, the real
`<button type="button">`s and the named remove buttons — they are behaviour, not
decoration, and `tests/Feature/AccessibilityTest.php` is what a published view
stops being covered by. And do not add an `<input>`.

## Why the domain package is in both `require` and `require-dev`

`liberusoftware/ecommerce-payment-operations` appears twice, and both entries are
load-bearing.

It is a **runtime** dependency: this package imports its actions, data types,
queries and models, so `require` is correct.

The shared test bootstrap, `Liberu\PackageTestbench\PackageTestCase`, boots a
sibling module's declared provider only when that sibling is in **`require-dev`**.
A runtime requirement deliberately never boots anything, because installing a
module must not enable it — that is the fleet rule `extra.laravel.providers` being
empty exists to enforce. Without the dev entry the domain's migrations never run
and every test fails on a missing table.

`composer validate` warns about the duplicate. Removing either entry breaks
something, so the warning stands.

## Version pinning

`^0.1` on the domain package. Below 1.0 Composer treats a minor bump as breaking,
so `^0.1` admits 0.1.x and refuses 0.2.0 — which is what is wanted while the
domain's read models are still settling.

## Upgrading

### 0.1.0

First release. Nothing to upgrade from.
